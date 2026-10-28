<?php

namespace App\Models;

use App\Concerns\HasOrganizations;
use App\Enums\InboxType;
use App\Enums\OrganizationRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_organization_id
 * @property string|null $avatar_path
 * @property string $timezone
 * @property string|null $signature_html
 * @property array<string, mixed>|null $preferences
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization|null $currentOrganization
 * @property-read Collection<int, Organization> $ownedOrganizations
 * @property-read Collection<int, Membership> $organizationMemberships
 * @property-read Collection<int, Organization> $organizations
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Inbox> $personalInboxes
 * @property-read Collection<int, MailAccount> $mailAccounts
 * @property-read Collection<int, Conversation> $assignedConversations
 */
#[Fillable(['name', 'email', 'password', 'current_organization_id', 'avatar_path', 'timezone', 'signature_html', 'preferences', 'last_seen_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasOrganizations, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Teams (inside organizations) the user belongs to.
     *
     * @return BelongsToMany<Team, $this, TeamMember, 'membership'>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')
            ->using(TeamMember::class)
            ->as('membership')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Personal inboxes owned by the user. These are private by default.
     *
     * @return HasMany<Inbox, $this>
     */
    public function personalInboxes(): HasMany
    {
        return $this->hasMany(Inbox::class)->where('type', InboxType::Personal->value)->orderBy('position');
    }

    /**
     * Personal mail accounts connected by the user.
     *
     * @return HasMany<MailAccount, $this>
     */
    public function mailAccounts(): HasMany
    {
        return $this->hasMany(MailAccount::class);
    }

    /**
     * Shared inboxes the user has been granted directly.
     *
     * @return BelongsToMany<Inbox, $this>
     */
    public function directInboxes(): BelongsToMany
    {
        return $this->belongsToMany(Inbox::class, 'inbox_users')->withPivot(['role'])->withTimestamps();
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assignee_id');
    }

    /**
     * @return BelongsToMany<Conversation, $this>
     */
    public function followedConversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_followers')->withTimestamps();
    }

    /**
     * IDs of organizations where the user is an owner or admin.
     *
     * @return array<int, int>
     */
    public function administeredOrganizationIds(): array
    {
        return $this->organizationMemberships()
            ->whereIn('role', [OrganizationRole::Owner->value, OrganizationRole::Admin->value])
            ->pluck('organization_id')
            ->all();
    }

    /**
     * Every inbox the user may open: personal ones plus shared ones via teams, direct grants or org admin rights.
     *
     * @return Builder<Inbox>
     */
    public function accessibleInboxes(): Builder
    {
        return Inbox::query()->accessibleBy($this);
    }

    public function canAccessInbox(Inbox $inbox): bool
    {
        return $inbox->roleFor($this) !== null;
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences, $key, $default);
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function initials(): string
    {
        return collect(explode(' ', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'preferences' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }
}
