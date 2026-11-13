<?php

namespace App\Models\Calls;

use App\Enums\Calls\ParticipantRole;
use App\Enums\Calls\ParticipantStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Calls\CallParticipantFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person in one call. Every in-call action (chat, files, recording, host controls) is
 * performed by a participant, never directly by a user, so guests and members share one path.
 * Authenticatable only so a guest can authorize the call's realtime channel; there is no
 * password and no way to sign in as a participant.
 *
 * @property int $id
 * @property string $uuid
 * @property int $call_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $email
 * @property ParticipantRole $role
 * @property ParticipantStatus $status
 * @property string|null $invite_token
 * @property int|null $invited_by_id
 * @property CarbonInterface|null $invited_at
 * @property CarbonInterface|null $admitted_at
 * @property CarbonInterface|null $joined_at
 * @property CarbonInterface|null $left_at
 * @property CarbonInterface|null $last_seen_at
 * @property CarbonInterface|null $recording_consent_at
 * @property int|null $last_read_message_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Call $call
 * @property-read User|null $user
 * @property-read User|null $invitedBy
 */
#[Fillable(['call_id', 'user_id', 'name', 'email', 'role', 'status', 'invite_token', 'invited_by_id', 'invited_at', 'admitted_at', 'joined_at', 'left_at', 'last_seen_at', 'recording_consent_at', 'last_read_message_id'])]
#[Hidden(['invite_token'])]
class CallParticipant extends Model implements AuthenticatableContract
{
    /** @use HasFactory<CallParticipantFactory> */
    use Authenticatable, HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (CallParticipant $participant) => $participant->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    /** The identity this participant has on the media server. */
    public function identity(): string
    {
        return 'p_'.$this->uuid;
    }

    public function isHost(): bool
    {
        return $this->role === ParticipantRole::Host;
    }

    public function isGuest(): bool
    {
        return $this->role === ParticipantRole::Guest;
    }

    public function isInside(): bool
    {
        return $this->status->isInside();
    }

    public function initials(): string
    {
        return collect(explode(' ', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    public function avatarUrl(): ?string
    {
        return $this->user?->avatarUrl();
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
            'role' => ParticipantRole::class,
            'status' => ParticipantStatus::class,
            'invited_at' => 'datetime',
            'admitted_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'recording_consent_at' => 'datetime',
        ];
    }
}
