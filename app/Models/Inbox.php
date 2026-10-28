<?php

namespace App\Models;

use App\Enums\InboxRole;
use App\Enums\InboxType;
use App\Enums\OrganizationRole;
use Database\Factories\InboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $organization_id
 * @property int|null $user_id
 * @property InboxType $type
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property string|null $icon
 * @property string|null $description
 * @property string|null $email_address
 * @property array<string, mixed>|null $settings
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization|null $organization
 * @property-read User|null $owner
 * @property-read Collection<int, MailAccount> $mailAccounts
 * @property-read Collection<int, Conversation> $conversations
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, User> $users
 */
#[Fillable(['organization_id', 'user_id', 'type', 'name', 'slug', 'color', 'icon', 'description', 'email_address', 'settings', 'position'])]
class Inbox extends Model
{
    /** @use HasFactory<InboxFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Inbox $inbox) {
            $inbox->uuid ??= (string) Str::uuid();

            if (empty($inbox->slug)) {
                $base = Str::slug($inbox->name) ?: 'inbox';
                $slug = $base;
                $i = 1;

                $query = fn (string $candidate) => static::withTrashed()
                    ->where('slug', $candidate)
                    ->when($inbox->organization_id, fn (Builder $q) => $q->where('organization_id', $inbox->organization_id))
                    ->when(! $inbox->organization_id, fn (Builder $q) => $q->whereNull('organization_id')->where('user_id', $inbox->user_id));

                while ($query($slug)->exists()) {
                    $slug = $base.'-'.(++$i);
                }

                $inbox->slug = $slug;
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<MailAccount, $this> */
    public function mailAccounts(): HasMany
    {
        return $this->hasMany(MailAccount::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<ConversationShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(ConversationShare::class);
    }

    /** @return BelongsToMany<Team, $this, InboxTeam, 'pivot'> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'inbox_teams')
            ->using(InboxTeam::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this, InboxUser, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'inbox_users')
            ->using(InboxUser::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /** @return HasMany<SavedView, $this> */
    public function savedViews(): HasMany
    {
        return $this->hasMany(SavedView::class);
    }

    public function isPersonal(): bool
    {
        return $this->type === InboxType::Personal;
    }

    public function isShared(): bool
    {
        return $this->type === InboxType::Shared;
    }

    /**
     * Resolve the effective role a user has on this inbox, or null when they have no access.
     */
    public function roleFor(User $user): ?InboxRole
    {
        if ($this->isPersonal()) {
            return $this->user_id === $user->id ? InboxRole::Admin : null;
        }

        if ($this->organization && $user->organizationRole($this->organization)?->isAtLeast(OrganizationRole::Admin)) {
            return InboxRole::Admin;
        }

        $direct = InboxUser::query()->where('inbox_id', $this->id)->where('user_id', $user->id)->first()?->role;

        $teamRoles = InboxTeam::query()
            ->where('inbox_id', $this->id)
            ->whereHas('team.members', fn (Builder $q) => $q->whereKey($user->id))
            ->get()
            ->map(fn (InboxTeam $grant) => $grant->role);

        return collect([$direct])
            ->merge($teamRoles)
            ->filter()
            ->sortByDesc(fn (InboxRole $role) => $role->level())
            ->first();
    }

    /**
     * Scope inboxes a user can access (personal ones they own, shared ones via team, direct grant, or org admin).
     *
     * @param  Builder<Inbox>  $query
     * @return Builder<Inbox>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where(fn (Builder $personal) => $personal->where('type', InboxType::Personal->value)->where('user_id', $user->id))
                ->orWhereHas('users', fn (Builder $direct) => $direct->whereKey($user->id))
                ->orWhereHas('teams.members', fn (Builder $members) => $members->whereKey($user->id))
                ->orWhereIn('organization_id', $user->administeredOrganizationIds());
        });
    }

    protected function casts(): array
    {
        return [
            'type' => InboxType::class,
            'settings' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
