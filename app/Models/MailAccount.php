<?php

namespace App\Models;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Enums\ProviderCapability;
use Carbon\CarbonInterface;
use Database\Factories\MailAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $organization_id
 * @property int|null $user_id
 * @property int $inbox_id
 * @property MailProvider $provider
 * @property string $email_address
 * @property string|null $display_name
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $sync_state
 * @property MailAccountStatus $status
 * @property CarbonInterface|null $last_synced_at
 * @property CarbonInterface|null $last_sync_started_at
 * @property CarbonInterface|null $initial_sync_completed_at
 * @property CarbonInterface|null $watch_expires_at
 * @property string|null $last_error
 * @property int $error_count
 * @property string|null $inbound_token
 * @property-read Inbox $inbox
 * @property-read Organization|null $organization
 * @property-read User|null $user
 * @property-read Collection<int, SenderIdentity> $identities
 * @property-read Collection<int, MailFolder> $folders
 * @property-read Collection<int, SyncRun> $syncRuns
 */
#[Fillable([
    'organization_id', 'user_id', 'inbox_id', 'provider', 'email_address', 'display_name', 'credentials',
    'settings', 'sync_state', 'status', 'last_synced_at', 'last_sync_started_at', 'initial_sync_completed_at',
    'watch_expires_at', 'last_error', 'error_count', 'inbound_token',
])]
class MailAccount extends Model
{
    /** @use HasFactory<MailAccountFactory> */
    use HasFactory, SoftDeletes;

    protected $hidden = ['credentials'];

    protected static function booted(): void
    {
        static::creating(function (MailAccount $account) {
            $account->uuid ??= (string) Str::uuid();
            $account->email_address = Str::lower($account->email_address);
        });
    }

    /** @return BelongsTo<Inbox, $this> */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SenderIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(SenderIdentity::class);
    }

    /** @return HasMany<MailFolder, $this> */
    public function folders(): HasMany
    {
        return $this->hasMany(MailFolder::class);
    }

    /** @return HasMany<SyncRun, $this> */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function supports(ProviderCapability $capability): bool
    {
        return $this->provider->supports($capability);
    }

    public function isPersonal(): bool
    {
        return $this->user_id !== null && $this->organization_id === null;
    }

    /**
     * Read a value from the encrypted credential bag.
     */
    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    /**
     * Merge values into the encrypted credential bag and persist.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeCredentials(array $values): void
    {
        $this->forceFill(['credentials' => array_merge($this->credentials ?? [], $values)])->save();
    }

    /**
     * Merge values into the provider sync cursor state and persist.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeSyncState(array $values): void
    {
        $this->forceFill(['sync_state' => array_merge($this->sync_state ?? [], $values)])->save();
    }

    public function syncState(string $key, mixed $default = null): mixed
    {
        return data_get($this->sync_state, $key, $default);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function markSyncStarted(): void
    {
        $this->forceFill([
            'status' => MailAccountStatus::Syncing,
            'last_sync_started_at' => now(),
        ])->save();
    }

    public function markSyncSucceeded(): void
    {
        $this->forceFill([
            'status' => MailAccountStatus::Connected,
            'last_synced_at' => now(),
            'last_error' => null,
            'error_count' => 0,
        ])->save();
    }

    public function markSyncFailed(string $error, bool $needsReauth = false): void
    {
        $this->forceFill([
            'status' => $needsReauth ? MailAccountStatus::NeedsReauth : MailAccountStatus::Error,
            'last_error' => Str::limit($error, 2000),
            'error_count' => $this->error_count + 1,
        ])->save();
    }

    public function forwardingAddress(): ?string
    {
        return $this->provider === MailProvider::Forwarding ? $this->email_address : null;
    }

    protected function casts(): array
    {
        return [
            'provider' => MailProvider::class,
            'status' => MailAccountStatus::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'sync_state' => 'array',
            'last_synced_at' => 'datetime',
            'last_sync_started_at' => 'datetime',
            'initial_sync_completed_at' => 'datetime',
            'watch_expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
