<?php

namespace App\Services\Calls;

use App\Enums\Calls\DeliveryStatus;
use App\Enums\Calls\ParticipantStatus;
use App\Jobs\Calls\SendCallTranscript;
use App\Models\Calls\Call;
use App\Models\Calls\CallFile;
use App\Models\Calls\CallMessage;
use App\Models\Calls\CallParticipant;
use App\Models\Calls\CallRecording;
use App\Models\Calls\CallTranscriptDelivery;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;

/**
 * Turns a call's chat into a transcript and gets it to people: as text to copy or download,
 * and as an email per recipient whose delivery is tracked.
 */
class CallTranscripts
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * Everything a transcript shows. Links to files and recordings are signed and expire, so
     * a recipient outside the organization can open exactly these and nothing else.
     *
     * @return array{
     *     title: string, code: string, organization: string, timezone: string,
     *     started_at: CarbonInterface|null, ended_at: CarbonInterface|null, duration: string|null,
     *     participants: list<array{name: string, role: string, is_guest: bool}>,
     *     messages: list<array{author: string, at: CarbonInterface|null, body: string|null, deleted: bool, edited: bool, reply_to: string|null, files: list<array{filename: string, size: string, url: string|null}>}>,
     *     recordings: list<array{label: string, duration: string, url: string}>,
     *     url: string, links_expire_at: CarbonInterface
     * }
     */
    public function build(Call $call, bool $includeFiles = true, bool $includeRecording = true): array
    {
        $timezone = $call->organization->timezone ?: (string) config('app.timezone');
        $expires = now()->addDays((int) config('calls.files.link_days', 14));

        $messages = array_values($call->messages()
            ->withTrashed()
            ->with(['participant', 'parent.participant', 'files'])
            ->orderBy('id')
            ->get()
            ->map(fn (CallMessage $message) => [
                'author' => $message->participant->name ?? 'Someone',
                'at' => $message->created_at?->setTimezone($timezone),
                'body' => $message->trashed() ? null : $message->body,
                'deleted' => $message->trashed(),
                'edited' => $message->edited_at !== null,
                'reply_to' => $message->parent?->participant?->name,
                'files' => $message->trashed() ? [] : array_values($message->files->map(fn (CallFile $file) => [
                    'filename' => $file->filename,
                    'size' => (string) Number::fileSize($file->size_bytes),
                    'url' => $includeFiles ? URL::temporarySignedRoute('meet.files.signed', $expires, ['file' => $file->uuid]) : null,
                ])->all()),
            ])
            ->all());

        $recordings = ! $includeRecording ? [] : array_values($call->recordings()
            ->whereIn('status', ['ready', 'partial'])
            ->orderBy('id')
            ->get()
            ->map(fn (CallRecording $recording) => [
                'label' => $recording->mode->label(),
                'duration' => $this->duration((int) round($recording->duration_ms / 1000)),
                'url' => URL::temporarySignedRoute('meet.recordings.signed', $expires, ['recording' => $recording->uuid]),
            ])
            ->all());

        return [
            'title' => $call->displayTitle(),
            'code' => $call->code,
            'organization' => $call->organization->name,
            'timezone' => $timezone,
            'started_at' => $call->started_at?->setTimezone($timezone),
            'ended_at' => $call->ended_at?->setTimezone($timezone),
            'duration' => $call->durationSeconds() !== null ? $this->duration($call->durationSeconds()) : null,
            'participants' => array_values($this->attendees($call)->map(fn (CallParticipant $participant) => [
                'name' => $participant->name,
                'role' => $participant->role->label(),
                'is_guest' => $participant->isGuest(),
            ])->all()),
            'messages' => $messages,
            'recordings' => $recordings,
            'url' => route('calls.show', ['current_organization' => $call->organization->slug, 'call' => $call->uuid]),
            'links_expire_at' => $expires,
        ];
    }

    /**
     * The plain-text transcript: what "Copy" and "Download" give you and the text part of the email.
     */
    public function text(Call $call, bool $includeFiles = true, bool $includeRecording = true): string
    {
        $data = $this->build($call, $includeFiles, $includeRecording);

        $lines = [$data['title'], str_repeat('=', mb_strlen($data['title'])), ''];

        if ($data['started_at']) {
            $lines[] = 'Date: '.$data['started_at']->format('l j F Y, H:i').' ('.$data['timezone'].')';
        }

        if ($data['duration']) {
            $lines[] = 'Duration: '.$data['duration'];
        }

        $lines[] = 'Participants: '.(implode(', ', array_map(fn (array $p) => $p['name'].($p['is_guest'] ? ' (guest)' : ''), $data['participants'])) ?: 'nobody');
        $lines[] = '';
        $lines[] = 'Chat';
        $lines[] = '----';

        if ($data['messages'] === []) {
            $lines[] = 'No messages were sent in this meeting.';
        }

        foreach ($data['messages'] as $message) {
            $head = '['.($message['at']?->format('H:i') ?? '--:--').'] '.$message['author'];

            if ($message['reply_to']) {
                $head .= ' (replying to '.$message['reply_to'].')';
            }

            if ($message['deleted']) {
                $lines[] = $head.': (message deleted)';

                continue;
            }

            $lines[] = $head.': '.str_replace("\n", "\n    ", (string) $message['body']).($message['edited'] ? ' (edited)' : '');

            foreach ($message['files'] as $file) {
                $lines[] = '    Attachment: '.$file['filename'].' ('.$file['size'].')'.($file['url'] ? ' '.$file['url'] : '');
            }
        }

        if ($data['recordings'] !== []) {
            $lines[] = '';
            $lines[] = 'Recordings';
            $lines[] = '----------';

            foreach ($data['recordings'] as $recording) {
                $lines[] = $recording['label'].' ('.$recording['duration'].'): '.$recording['url'];
            }
        }

        $lines[] = '';
        $lines[] = 'Open in '.config('app.name').': '.$data['url'];

        if (($includeFiles && $call->file_count > 0) || $data['recordings'] !== []) {
            $lines[] = 'File and recording links expire on '.$data['links_expire_at']->format('j F Y').'.';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Queue one tracked email per recipient.
     *
     * @param  list<array{email: string, name?: string|null}>  $recipients
     * @return Collection<int, CallTranscriptDelivery>
     */
    public function email(Call $call, ?User $sender, array $recipients, bool $includeFiles = true, bool $includeRecording = true, bool $automatic = false, ?CarbonInterface $delay = null): Collection
    {
        $deliveries = collect($recipients)
            ->unique(fn (array $recipient) => strtolower($recipient['email']))
            ->map(fn (array $recipient) => $call->transcriptDeliveries()->create([
                'sent_by_id' => $sender?->id,
                'email' => strtolower($recipient['email']),
                'name' => $recipient['name'] ?? null,
                'is_automatic' => $automatic,
                'include_files' => $includeFiles,
                'include_recording' => $includeRecording,
                'status' => DeliveryStatus::Queued,
            ]))
            ->values();

        $deliveries->each(fn (CallTranscriptDelivery $delivery) => SendCallTranscript::dispatch($delivery)->delay($delay));

        if ($deliveries->isNotEmpty()) {
            $call->forceFill(['transcript_emailed_at' => now()])->save();

            $this->audit->record('call.transcript_emailed', $call->organization, $sender, $call, [
                'recipients' => $deliveries->pluck('email')->all(),
                'automatic' => $automatic,
                'include_files' => $includeFiles,
                'include_recording' => $includeRecording,
            ]);
        }

        return $deliveries;
    }

    public function resend(CallTranscriptDelivery $delivery): void
    {
        $delivery->forceFill(['status' => DeliveryStatus::Queued, 'error' => null])->save();

        SendCallTranscript::dispatch($delivery);
    }

    /**
     * Everyone who was let into the call and has an address: the only people an automatic
     * transcript may go to.
     *
     * @return list<array{email: string, name: string}>
     */
    public function attendeeRecipients(Call $call): array
    {
        return array_values($this->attendees($call)
            ->filter(fn (CallParticipant $participant) => filled($participant->email))
            ->map(fn (CallParticipant $participant) => ['email' => (string) $participant->email, 'name' => $participant->name])
            ->unique(fn (array $recipient) => strtolower($recipient['email']))
            ->all());
    }

    /**
     * People who were actually in the meeting.
     *
     * @return Collection<int, CallParticipant>
     */
    public function attendees(Call $call): Collection
    {
        return $call->participants()
            ->whereNotNull('joined_at')
            ->whereNot('status', ParticipantStatus::Denied->value)
            ->orderBy('joined_at')
            ->get();
    }

    protected function duration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $hours > 0 => $hours.' h '.$minutes.' min',
            $minutes > 0 => $minutes.' min',
            default => $seconds.' s',
        };
    }
}
