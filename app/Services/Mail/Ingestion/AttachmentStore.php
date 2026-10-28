<?php

namespace App\Services\Mail\Ingestion;

use App\Models\Attachment;
use App\Models\Message;
use App\Services\Mail\Data\IncomingAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentStore
{
    public function disk(): string
    {
        return config('tandem.attachments.disk', 'local');
    }

    public function store(Message $message, IncomingAttachment $incoming): Attachment
    {
        $path = null;
        $checksum = null;

        if ($incoming->hasContent()) {
            $path = $this->pathFor($message, $incoming->filename);
            Storage::disk($this->disk())->put($path, (string) $incoming->content);
            $checksum = hash('sha256', (string) $incoming->content);
        }

        return $message->attachments()->create([
            'filename' => $incoming->filename,
            'mime_type' => $incoming->mimeType,
            'size_bytes' => $incoming->size,
            'content_id' => $incoming->contentId,
            'is_inline' => $incoming->isInline,
            'disk' => $this->disk(),
            'path' => $path,
            'provider_attachment_id' => $incoming->providerAttachmentId,
            'checksum' => $checksum,
        ]);
    }

    public function put(Attachment $attachment, string $content): void
    {
        $path = $attachment->path ?? $this->pathFor($attachment->message, $attachment->filename);

        Storage::disk($attachment->disk)->put($path, $content);

        $attachment->forceFill([
            'path' => $path,
            'size_bytes' => strlen($content),
            'checksum' => hash('sha256', $content),
        ])->save();
    }

    public function storeRawMime(Message $message, string $raw): string
    {
        $path = sprintf('mail/%s/%s/raw.eml', $message->conversation_id, $message->uuid);

        Storage::disk($this->disk())->put($path, $raw);

        return $path;
    }

    protected function pathFor(Message $message, string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $safe = Str::limit(Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'file', 60, '');

        return sprintf('mail/%s/%s/%s-%s%s', $message->conversation_id, $message->uuid, $safe, Str::lower(Str::random(6)), $extension ? '.'.Str::lower($extension) : '');
    }
}
