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
 * @property int $draft_id
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $disk
 * @property string $path
 * @property string|null $content_id
 * @property bool $is_inline
 * @property-read Draft $draft
 */
#[Fillable(['draft_id', 'filename', 'mime_type', 'size_bytes', 'disk', 'path', 'content_id', 'is_inline'])]
class DraftAttachment extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (DraftAttachment $attachment) => $attachment->uuid ??= (string) Str::uuid());
        static::deleted(function (DraftAttachment $attachment) {
            if (Storage::disk($attachment->disk)->exists($attachment->path)) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }
        });
    }

    /** @return BelongsTo<Draft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
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
