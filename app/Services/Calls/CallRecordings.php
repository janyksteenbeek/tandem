<?php

namespace App\Services\Calls;

use App\Enums\Calls\RecordingMode;
use App\Enums\Calls\RecordingStatus;
use App\Events\Calls\Broadcast\CallUpdated;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Models\Calls\CallRecording;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Calls\CallRecordingNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Calls\Media\Recorder;
use App\Services\Calls\Media\RecorderException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The life of a server-side recording. This application only decides and remembers: the
 * recorder does the capturing and reports back through signed webhooks. A recording
 * never fails quietly: every warning and failure is stored, broadcast to the room and, for
 * failures, sent to the host as a notification.
 */
class CallRecordings
{
    public function __construct(protected Recorder $recorder, protected AuditLogger $audit) {}

    /**
     * @throws ValidationException
     */
    public function start(Call $call, CallParticipant $by, RecordingMode $mode = RecordingMode::Full): CallRecording
    {
        if (! $call->isLive()) {
            throw ValidationException::withMessages(['recording' => 'Recording can start once someone is in the meeting.']);
        }

        if ($call->activeRecording()->exists()) {
            throw ValidationException::withMessages(['recording' => 'This meeting is already being recorded.']);
        }

        $uuid = (string) Str::uuid();

        $recording = $call->recordings()->make([
            'started_by_id' => $by->id,
            'mode' => $mode,
            'status' => RecordingStatus::Starting,
            'disk' => (string) config('calls.recordings.disk', 'local'),
            'path' => $call->uuid.'/'.$uuid,
            'heartbeat_at' => now(),
        ]);
        $recording->uuid = $uuid;
        $recording->save();
        $recording->setRelation('call', $call);

        try {
            $this->recorder->start($recording);
        } catch (RecorderException $exception) {
            $recording->forceFill(['status' => RecordingStatus::Failed, 'failure_reason' => $exception->getMessage(), 'ended_at' => now()])->save();
            Log::error('calls.recording_start_failed', ['recording' => $recording->uuid, 'error' => $exception->getMessage()]);

            throw ValidationException::withMessages(['recording' => $exception->getMessage()]);
        }

        // Whoever starts the recording has, by doing so, agreed to it.
        $by->forceFill(['recording_consent_at' => now()])->save();

        CallUpdated::dispatch($call, 'recording.starting', ['by' => $by->name]);
        $this->audit->record('call.recording_started', $call->organization, $by->user, $recording, ['by' => $by->name, 'mode' => $mode->value]);

        return $recording;
    }

    public function stop(CallRecording $recording, ?CallParticipant $by = null): CallRecording
    {
        if (! $recording->status->isActive()) {
            return $recording;
        }

        try {
            $this->recorder->stop($recording);
        } catch (RecorderException $exception) {
            // The recorder finalises on its own when the room closes or it restarts; keep the
            // state honest and let the stall check settle it if no report ever arrives.
            Log::warning('calls.recording_stop_failed', ['recording' => $recording->uuid, 'error' => $exception->getMessage()]);
            $recording->last_warning = 'The recorder did not confirm the stop request: '.$exception->getMessage();
        }

        $recording->forceFill([
            'status' => RecordingStatus::Processing,
            'stopped_by_id' => $by?->id,
            'ended_at' => now(),
            'heartbeat_at' => now(),
        ])->save();

        CallUpdated::dispatch($recording->call, 'recording.stopped', ['by' => $by?->name]);

        if ($by !== null) {
            $this->audit->record('call.recording_stopped', $recording->call->organization, $by->user, $recording, ['by' => $by->name]);
        }

        return $recording;
    }

    /**
     * Apply one event from the recorder webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleRecorderEvent(CallRecording $recording, string $event, array $payload): void
    {
        $call = $recording->call;

        match ($event) {
            'recording.started' => $this->onStarted($recording, $payload),
            'recording.progress' => $this->onProgress($recording, $payload),
            'recording.warning' => $this->onWarning($recording, $payload),
            'recording.finished' => $this->onFinished($recording, (array) ($payload['manifest'] ?? [])),
            'recording.failed' => $this->onFailed($recording, (string) ($payload['reason'] ?? 'The recorder reported a failure.'), is_array($payload['manifest'] ?? null) ? $payload['manifest'] : null),
            default => Log::info('calls.recorder_event_ignored', ['event' => $event, 'recording' => $recording->uuid]),
        };

        if ($event !== 'recording.progress') {
            CallUpdated::dispatch($call, $event, array_filter(['message' => $payload['message'] ?? $payload['reason'] ?? null]));
        }
    }

    /**
     * Recordings that should be reporting but have gone quiet. Called every minute.
     */
    public function checkStalled(): int
    {
        $stallAfter = (int) config('calls.recorder.stall_seconds', 90);
        $count = 0;

        CallRecording::query()
            ->whereIn('status', [RecordingStatus::Starting->value, RecordingStatus::Recording->value, RecordingStatus::Processing->value])
            ->where('heartbeat_at', '<', now()->subSeconds($stallAfter))
            ->with('call.organization')
            ->each(function (CallRecording $recording) use (&$count, $stallAfter) {
                $count++;
                $unreachable = null;

                try {
                    $remote = $this->recorder->status($recording);
                } catch (RecorderException $exception) {
                    $remote = null;
                    $unreachable = $exception->getMessage();
                }

                if (is_array($remote) && in_array($remote['status'] ?? null, ['complete', 'partial'], true) && is_array($remote['manifest'] ?? null)) {
                    // The webhook was lost; the recorder has the finished manifest.
                    $this->onFinished($recording, $remote['manifest']);
                    CallUpdated::dispatch($recording->call, 'recording.finished');

                    return;
                }

                if (is_array($remote) && ($remote['status'] ?? null) === 'failed') {
                    $this->onFailed($recording, 'The recorder could not complete this recording.', is_array($remote['manifest'] ?? null) ? $remote['manifest'] : null);
                    CallUpdated::dispatch($recording->call, 'recording.failed', ['message' => $recording->failure_reason]);

                    return;
                }

                if (is_array($remote) && in_array($remote['status'] ?? null, ['starting', 'recording', 'finalizing'], true)) {
                    $recording->forceFill(['heartbeat_at' => now()])->save();

                    return;
                }

                // Give a restarting recorder ten stall periods to come back and recover before
                // declaring the recording failed.
                if ($recording->heartbeat_at !== null && $recording->heartbeat_at->lt(now()->subSeconds($stallAfter * 10))) {
                    $this->onFailed($recording, 'The recorder stopped reporting and did not recover. '.($unreachable ?? 'It no longer knows this recording.'), null);
                    CallUpdated::dispatch($recording->call, 'recording.failed', ['message' => $recording->failure_reason]);

                    return;
                }

                if ($recording->status->isActive() && $recording->last_warning === null) {
                    $this->onWarning($recording, ['message' => 'The recorder is not responding. Recording may be interrupted.', 'code' => 'stalled']);
                    CallUpdated::dispatch($recording->call, 'recording.warning', ['message' => $recording->last_warning]);
                }
            });

        return $count;
    }

    /**
     * Create or refresh the secret link that lets someone without an account watch.
     */
    public function share(CallRecording $recording, User $by): string
    {
        $token = Str::random(48);

        $recording->forceFill([
            'share_token' => hash('sha256', $token),
            'share_expires_at' => now()->addDays((int) config('calls.recordings.share_link_days', 30)),
        ])->save();

        $this->audit->record('call.recording_shared', $recording->call->organization, $by, $recording);

        return route('meet.recordings.shared', ['token' => $token]);
    }

    public function revokeShare(CallRecording $recording, User $by): void
    {
        $recording->forceFill(['share_token' => null, 'share_expires_at' => null])->save();

        $this->audit->record('call.recording_share_revoked', $recording->call->organization, $by, $recording);
    }

    public function findShared(string $token): ?CallRecording
    {
        $recording = CallRecording::query()->where('share_token', hash('sha256', $token))->first();

        return $recording?->isShareLinkValid() && $recording->status->isPlayable() ? $recording : null;
    }

    /**
     * Delete the recording, its exports and every file it wrote.
     */
    public function delete(CallRecording $recording, ?User $by = null, string $reason = 'deleted'): void
    {
        if ($recording->status->isActive()) {
            $this->stop($recording);
        }

        $recording->exports()->get()->each->delete();

        $disk = Storage::disk($recording->disk);
        $directory = $this->directory($recording);
        $disk->deleteDirectory($directory);

        // Do not leave an empty folder per call behind once its last recording is gone.
        if ($disk->allFiles(dirname($directory)) === []) {
            $disk->deleteDirectory(dirname($directory));
        }

        $this->audit->record('call.recording_'.$reason, $recording->call->organization, $by, $recording->call, [
            'recording' => $recording->uuid,
            'duration_ms' => $recording->duration_ms,
        ]);

        $recording->delete();
    }

    /**
     * Remove recordings past their retention date. Returns how many were deleted.
     */
    public function prune(): int
    {
        $count = 0;

        CallRecording::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with('call.organization')
            ->each(function (CallRecording $recording) use (&$count) {
                $this->delete($recording, null, 'expired');
                $count++;
            });

        return $count;
    }

    /** Where a recording's files live, relative to its disk. */
    public function directory(CallRecording $recording): string
    {
        return trim((string) config('calls.recordings.path', 'calls/recordings'), '/').'/'.$recording->path;
    }

    /**
     * How long the organization keeps recordings; null means until someone deletes them.
     */
    public function retentionDays(Organization $organization): ?int
    {
        $days = $organization->call_settings['recording_retention_days'] ?? config('calls.recordings.retention_days');

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function onStarted(CallRecording $recording, array $payload): void
    {
        if (! $recording->status->isActive()) {
            return;
        }

        $recording->forceFill([
            'status' => RecordingStatus::Recording,
            'started_at' => $this->time($payload['started_at'] ?? null) ?? now(),
            'heartbeat_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function onProgress(CallRecording $recording, array $payload): void
    {
        $recording->forceFill([
            'duration_ms' => max($recording->duration_ms, (int) ($payload['duration_ms'] ?? 0)),
            'size_bytes' => max($recording->size_bytes, (int) ($payload['size_bytes'] ?? 0)),
            'heartbeat_at' => now(),
        ]);

        if ($recording->status === RecordingStatus::Starting) {
            $recording->status = RecordingStatus::Recording;
            $recording->started_at ??= now();
        }

        $recording->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function onWarning(CallRecording $recording, array $payload): void
    {
        $recovered = ($payload['code'] ?? null) === 'recovered';

        $recording->forceFill([
            'last_warning' => $recovered ? null : Str::limit((string) ($payload['message'] ?? 'The recorder reported a problem.'), 480, ''),
            'heartbeat_at' => now(),
        ])->save();

        Log::warning('calls.recording_warning', ['recording' => $recording->uuid, 'code' => $payload['code'] ?? null, 'message' => $payload['message'] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    protected function onFinished(CallRecording $recording, array $manifest): void
    {
        $partial = ($manifest['status'] ?? 'complete') !== 'complete';
        $hasTracks = count((array) ($manifest['tracks'] ?? [])) > 0;
        $endedAt = $this->time($manifest['ended_at'] ?? null) ?? $recording->ended_at ?? now();
        $retention = $this->retentionDays($recording->call->organization);

        $recording->forceFill([
            'status' => ! $hasTracks ? RecordingStatus::Failed : ($partial ? RecordingStatus::Partial : RecordingStatus::Ready),
            'manifest' => $manifest,
            'duration_ms' => (int) ($manifest['duration_ms'] ?? $recording->duration_ms),
            'size_bytes' => (int) ($manifest['size_bytes'] ?? $recording->size_bytes),
            'started_at' => $this->time($manifest['started_at'] ?? null) ?? $recording->started_at,
            'ended_at' => $endedAt,
            'heartbeat_at' => now(),
            'failure_reason' => $hasTracks ? null : 'Nothing was captured: nobody published audio or video while recording.',
            'last_warning' => $partial && $hasTracks ? 'Part of this recording could not be captured ('.($manifest['stop_reason'] ?? 'interrupted').').' : null,
            'expires_at' => $retention ? Carbon::parse($endedAt)->addDays($retention) : null,
        ])->save();

        $this->notifyHost($recording, $hasTracks ? ($partial ? 'partial' : 'ready') : 'failed');
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     */
    protected function onFailed(CallRecording $recording, string $reason, ?array $manifest): void
    {
        $salvaged = $manifest !== null && count((array) ($manifest['tracks'] ?? [])) > 0;
        $retention = $this->retentionDays($recording->call->organization);

        $recording->forceFill([
            'status' => $salvaged ? RecordingStatus::Partial : RecordingStatus::Failed,
            'manifest' => $manifest ?? $recording->manifest,
            'duration_ms' => (int) ($manifest['duration_ms'] ?? $recording->duration_ms),
            'size_bytes' => (int) ($manifest['size_bytes'] ?? $recording->size_bytes),
            'failure_reason' => Str::limit($reason, 480, ''),
            'ended_at' => $recording->ended_at ?? now(),
            'heartbeat_at' => now(),
            'expires_at' => $salvaged && $retention ? now()->addDays($retention) : null,
        ])->save();

        Log::error('calls.recording_failed', ['recording' => $recording->uuid, 'reason' => $reason, 'salvaged' => $salvaged]);

        $this->audit->record('call.recording_failed', $recording->call->organization, null, $recording, ['reason' => $reason, 'salvaged' => $salvaged]);
        $this->notifyHost($recording, $salvaged ? 'partial' : 'failed');
    }

    /**
     * @param  'ready'|'partial'|'failed'  $outcome
     */
    protected function notifyHost(CallRecording $recording, string $outcome): void
    {
        $recipients = collect([$recording->call->host, $recording->startedBy?->user])->filter()->unique('id');

        foreach ($recipients as $user) {
            $user->notify(new CallRecordingNotification($recording, $outcome));
        }
    }

    protected function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
