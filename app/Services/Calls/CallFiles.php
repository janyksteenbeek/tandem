<?php

namespace App\Services\Calls;

use App\Models\Calls\Call;
use App\Models\Calls\CallFile;
use App\Models\Calls\CallParticipant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Files shared in a meeting. They live on the same private disk as mail and issue
 * attachments, with the same blocked extensions, and are never reachable by a public path.
 */
class CallFiles
{
    public function store(Call $call, CallParticipant $participant, UploadedFile $file): CallFile
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, (array) config('tandem.attachments.blocked_extensions', []), true)) {
            throw ValidationException::withMessages(['file' => 'That file type is not allowed.']);
        }

        $disk = (string) config('tandem.attachments.disk', 'local');
        $path = $file->storeAs('calls/'.$call->uuid.'/files', Str::random(16).($extension ? '.'.$extension : ''), $disk);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored. Try again.']);
        }

        return $call->files()->create([
            'participant_id' => $participant->id,
            'filename' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'disk' => $disk,
            'path' => (string) $path,
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
        ]);
    }

    /**
     * Drop an upload that was never sent with a message.
     */
    public function discard(CallFile $file, CallParticipant $participant): bool
    {
        if ($file->participant_id !== $participant->id || $file->message_id !== null) {
            return false;
        }

        return (bool) $file->delete();
    }
}
