<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $message_id
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $content_id
 * @property bool $is_inline
 * @property string $disk
 * @property string|null $path
 * @property string|null $provider_attachment_id
 * @property string|null $checksum
 * @property-read Message $message
 */
#[Fillable(['message_id', 'filename', 'mime_type', 'size_bytes', 'content_id', 'is_inline', 'disk', 'path', 'provider_attachment_id', 'checksum'])]
class Attachment extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (Attachment $attachment) => $attachment->uuid ??= (string) Str::uuid());
        static::deleted(function (Attachment $attachment) {
            if ($attachment->path && Storage::disk($attachment->disk)->exists($attachment->path)) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }
        });
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isStored(): bool
    {
        return $this->path !== null && Storage::disk($this->disk)->exists($this->path);
    }

    public function isImage(): bool
    {
        return Str::startsWith($this->mime_type, 'image/');
    }

    protected function casts(): array
    {
        return ['is_inline' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
