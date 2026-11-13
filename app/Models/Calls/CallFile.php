<?php

namespace App\Models\Calls;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file shared in a call's chat. Kept on the private attachments disk and only ever served
 * through CallFileController or a signed, expiring link.
 *
 * @property int $id
 * @property string $uuid
 * @property int $call_id
 * @property int|null $message_id
 * @property int|null $participant_id
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $disk
 * @property string $path
 * @property string|null $checksum
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Call $call
 * @property-read CallMessage|null $message
 * @property-read CallParticipant|null $participant
 */
#[Fillable(['call_id', 'message_id', 'participant_id', 'filename', 'mime_type', 'size_bytes', 'disk', 'path', 'checksum'])]
class CallFile extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (CallFile $file) => $file->uuid ??= (string) Str::uuid());

        static::deleted(function (CallFile $file) {
            if ($file->path) {
                Storage::disk($file->disk)->delete($file->path);
            }
        });
    }

    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /** @return BelongsTo<CallMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(CallMessage::class, 'message_id');
    }

    /** @return BelongsTo<CallParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(CallParticipant::class, 'participant_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/') && ! str_starts_with($this->mime_type, 'image/svg');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
