<?php

namespace App\Models\Calls;

use App\Enums\Calls\DeliveryStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One transcript email to one recipient, so delivery can be shown and retried per person.
 *
 * @property int $id
 * @property string $uuid
 * @property int $call_id
 * @property int|null $sent_by_id
 * @property string $email
 * @property string|null $name
 * @property bool $is_automatic
 * @property bool $include_files
 * @property bool $include_recording
 * @property DeliveryStatus $status
 * @property string|null $error
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Call $call
 * @property-read User|null $sentBy
 */
#[Fillable(['call_id', 'sent_by_id', 'email', 'name', 'is_automatic', 'include_files', 'include_recording', 'status', 'error', 'sent_at'])]
class CallTranscriptDelivery extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (CallTranscriptDelivery $delivery) => $delivery->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id');
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
            'is_automatic' => 'boolean',
            'include_files' => 'boolean',
            'include_recording' => 'boolean',
            'status' => DeliveryStatus::class,
            'sent_at' => 'datetime',
        ];
    }
}
