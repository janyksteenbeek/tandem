<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueOrganizationSlugs;
use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property string $timezone
 * @property string $color
 * @property string|null $google_oauth_client_id
 * @property string|null $google_oauth_client_secret
 * @property string|null $google_pubsub_topic
 * @property array<string, mixed>|null $business_hours
 * @property array<string, mixed>|null $outbound_mail_settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, OrganizationInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Inbox> $inboxes
 * @property-read Collection<int, MailAccount> $mailAccounts
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, AutomationRule> $automationRules
 * @property-read Collection<int, MessageTemplate> $messageTemplates
 */
#[Fillable(['name', 'slug', 'is_personal', 'timezone', 'color', 'google_oauth_client_id', 'google_oauth_client_secret', 'google_pubsub_topic', 'business_hours', 'outbound_mail_settings'])]
#[Hidden(['google_oauth_client_secret', 'outbound_mail_settings'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use GeneratesUniqueOrganizationSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Organization $organization) {
            if (empty($organization->slug)) {
                $organization->slug = static::generateUniqueOrganizationSlug($organization->name);
            }
        });

        static::updating(function (Organization $organization) {
            if ($organization->isDirty('name')) {
                $organization->slug = static::generateUniqueOrganizationSlug($organization->name, $organization->id);
            }
        });
    }

    /**
     * Get the organization owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', OrganizationRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this organization.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members', 'organization_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this organization.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this organization.
     *
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class)->orderBy('name');
    }

    /**
     * @return HasMany<Inbox, $this>
     */
    public function inboxes(): HasMany
    {
        return $this->hasMany(Inbox::class)->orderBy('position')->orderBy('name');
    }

    /**
     * @return HasMany<MailAccount, $this>
     */
    public function mailAccounts(): HasMany
    {
        return $this->hasMany(MailAccount::class);
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class)->orderBy('name');
    }

    /**
     * @return HasMany<AutomationRule, $this>
     */
    public function automationRules(): HasMany
    {
        return $this->hasMany(AutomationRule::class)->orderBy('position');
    }

    /**
     * @return HasMany<MessageTemplate, $this>
     */
    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class)->orderBy('name');
    }

    /**
     * Whether this organization has its own Google OAuth client configured.
     */
    public function hasGoogleOAuthCredentials(): bool
    {
        return filled($this->google_oauth_client_id) && filled($this->google_oauth_client_secret);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'google_oauth_client_secret' => 'encrypted',
            'business_hours' => 'array',
            'outbound_mail_settings' => 'encrypted:array',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
