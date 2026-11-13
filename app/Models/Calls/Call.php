<?php

namespace App\Models\Calls;

use App\Enums\Calls\CallStatus;
use App\Enums\Calls\ParticipantStatus;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Calls\CallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A meeting. Addressed by its `code` in shareable links (/meet/abc-defg-hij) and by its uuid
 * inside the organization (history, recordings).
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int|null $host_id
 * @property string $code
 * @property string|null $title
 * @property CallStatus $status
 * @property string|null $password
 * @property array<string, mixed>|null $settings
 * @property int $message_count
 * @property int $file_count
 * @property int $peak_participants
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $ended_at
 * @property CarbonInterface|null $transcript_emailed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Organization $organization
 * @property-read User|null $host
 * @property-read Collection<int, CallParticipant> $participants
 * @property-read Collection<int, CallMessage> $messages
 * @property-read Collection<int, CallFile> $files
 * @property-read Collection<int, CallRecording> $recordings
 * @property-read Collection<int, CallTranscriptDelivery> $transcriptDeliveries
 * @property-read Collection<int, CallLink> $links
 * @property-read CallRecording|null $activeRecording
 */
#[Fillable(['organization_id', 'host_id', 'code', 'title', 'status', 'password', 'settings', 'message_count', 'file_count', 'peak_participants', 'started_at', 'ended_at', 'transcript_emailed_at'])]
#[Hidden(['password'])]
class Call extends Model
{
    /** @use HasFactory<CallFactory> */
    use HasFactory, SoftDeletes;

    /**
     * What a call does unless the organization or the host says otherwise.
     *
     * @var array<string, mixed>
     */
    public const DEFAULT_SETTINGS = [
        // Guests wait until a host lets them in. Organization members always walk in.
        'waiting_room' => true,
        // Nobody new may join, not even members.
        'locked' => false,
        // "everyone" or "hosts".
        'screen_share' => 'everyone',
        // Who may start a recording: "hosts" or "members". Guests never can.
        'recording' => 'hosts',
        'guest_uploads' => true,
        'auto_email_transcript' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (Call $call) {
            $call->uuid ??= (string) Str::uuid();
            $call->code ??= static::generateCode();
        });
    }

    /**
     * A meeting code in the familiar abc-defg-hij shape. Ten random letters: enough that codes
     * cannot be guessed at the rate the join endpoint allows.
     */
    public static function generateCode(): string
    {
        do {
            $letters = '';

            for ($i = 0; $i < 10; $i++) {
                $letters .= chr(random_int(97, 122));
            }

            $code = substr($letters, 0, 3).'-'.substr($letters, 3, 4).'-'.substr($letters, 7, 3);
        } while (static::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /** @return HasMany<CallParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class);
    }

    /** @return HasMany<CallMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(CallMessage::class);
    }

    /** @return HasMany<CallFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(CallFile::class);
    }

    /** @return HasMany<CallRecording, $this> */
    public function recordings(): HasMany
    {
        return $this->hasMany(CallRecording::class);
    }

    /** @return HasOne<CallRecording, $this> */
    public function activeRecording(): HasOne
    {
        return $this->hasOne(CallRecording::class)->whereIn('status', ['starting', 'recording'])->latestOfMany();
    }

    /** @return HasMany<CallTranscriptDelivery, $this> */
    public function transcriptDeliveries(): HasMany
    {
        return $this->hasMany(CallTranscriptDelivery::class);
    }

    /** @return HasMany<CallLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(CallLink::class);
    }

    /**
     * Calls the user may see in history: every call of an organization they belong to that they
     * hosted or took part in. Organization admins see all of them.
     *
     * @param  Builder<Call>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, Organization $organization): void
    {
        $query->where('organization_id', $organization->id);

        if (in_array($organization->id, $user->administeredOrganizationIds(), true)) {
            return;
        }

        $query->where(fn (Builder $inner) => $inner
            ->where('host_id', $user->id)
            ->orWhereHas('participants', fn (Builder $participants) => $participants->where('user_id', $user->id)));
    }

    public function setting(string $key): mixed
    {
        return $this->allSettings()[$key] ?? null;
    }

    /**
     * The call's own settings on top of the organization defaults on top of the built-in ones.
     *
     * @return array<string, mixed>
     */
    public function allSettings(): array
    {
        $organizationDefaults = array_intersect_key((array) ($this->organization->call_settings['defaults'] ?? []), self::DEFAULT_SETTINGS);

        return [...self::DEFAULT_SETTINGS, ...$organizationDefaults, ...array_intersect_key($this->settings ?? [], self::DEFAULT_SETTINGS)];
    }

    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    public function isLive(): bool
    {
        return $this->status === CallStatus::Live;
    }

    public function hasEnded(): bool
    {
        return $this->status === CallStatus::Ended;
    }

    /** The LiveKit room this call lives in. */
    public function roomName(): string
    {
        return 'call-'.$this->uuid;
    }

    public function displayTitle(): string
    {
        return $this->title ?: 'Meeting '.$this->code;
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->ended_at ?? now());
    }

    public function connectedCount(): int
    {
        return $this->participants()->where('status', ParticipantStatus::Joined->value)->count();
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
            'status' => CallStatus::class,
            'password' => 'hashed',
            'settings' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'transcript_emailed_at' => 'datetime',
        ];
    }
}
