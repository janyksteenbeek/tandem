<?php

namespace App\Services\Calls;

use App\Events\Calls\Broadcast\CallMessageChanged;
use App\Models\Calls\Call;
use App\Models\Calls\CallMessage;
use App\Models\Calls\CallParticipant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Meeting chat. The database is the source of truth and Reverb only announces changes, so a
 * browser that was offline catches up by asking for everything after the last id it has.
 */
class CallChat
{
    public const MAX_LENGTH = 8000;

    /**
     * Post a message. `clientId` makes the call idempotent: a browser that retries after a
     * lost response gets the message it already stored instead of a duplicate.
     *
     * @param  list<string>  $fileUuids  Files this participant uploaded and has not attached yet.
     */
    public function post(Call $call, CallParticipant $author, ?string $body, ?string $clientId = null, ?CallMessage $parent = null, array $fileUuids = []): CallMessage
    {
        $body = $this->clean($body);

        if ($clientId !== null && ($existing = $this->findByClientId($call, $author, $clientId))) {
            return $existing;
        }

        $files = $fileUuids === [] ? collect() : $call->files()
            ->whereIn('uuid', $fileUuids)
            ->where('participant_id', $author->id)
            ->whereNull('message_id')
            ->get();

        if ($body === null && $files->isEmpty()) {
            throw ValidationException::withMessages(['body' => 'Write a message or attach a file.']);
        }

        try {
            $message = DB::transaction(function () use ($call, $author, $body, $clientId, $parent, $files) {
                $message = $call->messages()->create([
                    'participant_id' => $author->id,
                    'parent_id' => $parent?->id,
                    'client_id' => $clientId,
                    'body' => $body,
                    'type' => $body === null ? 'file' : 'text',
                ]);

                if ($files->isNotEmpty()) {
                    $call->files()->whereKey($files->pluck('id')->all())->update(['message_id' => $message->id]);
                }

                $call->forceFill([
                    'message_count' => $call->messages()->count(),
                    'file_count' => $call->files()->whereNotNull('message_id')->count(),
                ])->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two retries of the same optimistic message raced; the first one won.
            return $this->findByClientId($call, $author, (string) $clientId) ?? throw $exception;
        }

        $message->setRelation('call', $call);
        $author->forceFill(['last_read_message_id' => $message->id])->save();

        CallMessageChanged::dispatch($message, 'created');

        return $message;
    }

    public function update(CallMessage $message, string $body): CallMessage
    {
        $body = $this->clean($body);

        if ($body === null) {
            throw ValidationException::withMessages(['body' => 'A message cannot be empty.']);
        }

        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();

        CallMessageChanged::dispatch($message, 'updated');

        return $message;
    }

    /**
     * Remove a message and the files shared with it. The row stays as a tombstone so replies
     * and the transcript keep their place.
     */
    public function delete(CallMessage $message): void
    {
        $call = $message->call;

        DB::transaction(function () use ($message, $call) {
            $message->files()->get()->each->delete();
            $message->reactions()->delete();
            $message->delete();

            $call->forceFill([
                'message_count' => $call->messages()->count(),
                'file_count' => $call->files()->whereNotNull('message_id')->count(),
            ])->save();
        });

        CallMessageChanged::dispatch($message, 'deleted');
    }

    public function react(CallMessage $message, CallParticipant $participant, string $emoji, bool $on): CallMessage
    {
        $emoji = Str::limit(trim($emoji), 32, '');

        if ($on) {
            $message->reactions()->firstOrCreate(['participant_id' => $participant->id, 'emoji' => $emoji]);
        } else {
            $message->reactions()->where('participant_id', $participant->id)->where('emoji', $emoji)->delete();
        }

        $message->unsetRelation('reactions');

        CallMessageChanged::dispatch($message, 'updated');

        return $message;
    }

    public function markRead(CallParticipant $participant, int $messageId): void
    {
        if ($messageId > (int) $participant->last_read_message_id) {
            $participant->forceFill(['last_read_message_id' => $messageId])->save();
        }
    }

    public function canEdit(CallMessage $message, CallParticipant $participant): bool
    {
        return $message->participant_id === $participant->id && ! $message->trashed();
    }

    public function canDelete(CallMessage $message, CallParticipant $participant): bool
    {
        return $this->canEdit($message, $participant) || ParticipantAbilities::for($participant)->manage();
    }

    protected function findByClientId(Call $call, CallParticipant $author, string $clientId): ?CallMessage
    {
        return $call->messages()->withTrashed()->where('participant_id', $author->id)->where('client_id', $clientId)->first();
    }

    protected function clean(?string $body): ?string
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string) $body));

        return $body === '' ? null : Str::limit($body, self::MAX_LENGTH, '');
    }
}
