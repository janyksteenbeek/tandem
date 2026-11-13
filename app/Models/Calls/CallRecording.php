<?php

namespace App\Models\Calls;

use App\Enums\Calls\RecordingMode;
use App\Enums\Calls\RecordingStatus;
use Carbon\CarbonInterface;
use Database\Factories\Calls\CallRecordingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One server-side recording of a call: a directory of per-track WebM files written by the
 * recorder plus the manifest that places them on a shared timeline.
 *
 * @property int $id
 * @property string $uuid
 * @property int $call_id
 * @property int|null $started_by_id
 * @property int|null $stopped_by_id
 * @property RecordingMode $mode
 * @property RecordingStatus $status
 * @property string $disk
 * @property string $path
 * @property array<string, mixed>|null $manifest
 * @property int $duration_ms
 * @property int $size_bytes
 * @property string|null $failure_reason
 * @property string|null $last_warning
 * @property string|null $share_token
 * @property CarbonInterface|null $share_expires_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $ended_at
 * @property CarbonInterface|null $heartbeat_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Call $call
 * @property-read CallParticipant|null $startedBy
 * @property-read Collection<int, CallRecordingExport> $exports
 */
#[Fillable(['call_id', 'started_by_id', 'stopped_by_id', 'mode', 'status', 'disk', 'path', 'manifest', 'duration_ms', 'size_bytes', 'failure_reason', 'last_warning', 'share_token', 'share_expires_at', 'started_at', 'ended_at', 'heartbeat_at', 'expires_at'])]
#[Hidden(['share_token'])]
class CallRecording extends Model
{
    /** @use HasFactory<CallRecordingFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (CallRecording $recording) => $recording->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /** @return BelongsTo<CallParticipant, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(CallParticipant::class, 'started_by_id');
    }

    /** @return HasMany<CallRecordingExport, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(CallRecordingExport::class, 'recording_id');
    }

    /**
     * The track entries of the manifest.
     *
     * @return list<array<string, mixed>>
     */
    public function tracks(): array
    {
        return array_values(array_filter((array) ($this->manifest['tracks'] ?? []), 'is_array'));
    }

    /**
     * The manifest entry for one of this recording's files, or null when the name is not one
     * of them. File names only ever come from the manifest, never from the request.
     *
     * @return array<string, mixed>|null
     */
    public function track(string $file): ?array
    {
        foreach ($this->tracks() as $track) {
            if (($track['file'] ?? null) === $file) {
                return $track;
            }
        }

        return null;
    }

    public function hasScreenShare(): bool
    {
        return collect($this->tracks())->contains(fn (array $track) => ($track['source'] ?? null) === 'screen_share');
    }

    public function isShareLinkValid(): bool
    {
        return filled($this->share_token) && ($this->share_expires_at === null || $this->share_expires_at->isFuture());
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
            'mode' => RecordingMode::class,
            'status' => RecordingStatus::class,
            'manifest' => 'array',
            'share_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
