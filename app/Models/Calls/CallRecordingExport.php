<?php

namespace App\Models\Calls;

use App\Enums\Calls\ExportKind;
use App\Enums\Calls\ExportStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $recording_id
 * @property int|null $requested_by_id
 * @property ExportKind $kind
 * @property ExportStatus $status
 * @property string $disk
 * @property string|null $path
 * @property string|null $filename
 * @property int $size_bytes
 * @property string|null $error
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read CallRecording $recording
 * @property-read User|null $requestedBy
 */
#[Fillable(['recording_id', 'requested_by_id', 'kind', 'status', 'disk', 'path', 'filename', 'size_bytes', 'error', 'finished_at'])]
class CallRecordingExport extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (CallRecordingExport $export) => $export->uuid ??= (string) Str::uuid());

        static::deleted(function (CallRecordingExport $export) {
            if ($export->path) {
                Storage::disk($export->disk)->delete($export->path);
            }
        });
    }

    /** @return BelongsTo<CallRecording, $this> */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(CallRecording::class, 'recording_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ExportKind::class,
            'status' => ExportStatus::class,
            'finished_at' => 'datetime',
        ];
    }
}
