<?php

namespace App\Models;

use App\Enums\TeamRole;
use Carbon\CarbonInterface;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property string|null $description
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Organization $organization
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Inbox> $inboxes
 */
#[Fillable(['organization_id', 'name', 'slug', 'color', 'description'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $base = Str::slug($team->name) ?: 'team';
                $slug = $base;
                $i = 1;

                while (static::withTrashed()->where('organization_id', $team->organization_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.(++$i);
                }

                $team->slug = $slug;
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<User, $this, TeamMember, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->using(TeamMember::class)
            ->as('membership')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Inbox, $this> */
    public function inboxes(): BelongsToMany
    {
        return $this->belongsToMany(Inbox::class, 'inbox_teams')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function roleOf(User $user): ?TeamRole
    {
        return TeamMember::query()->where('team_id', $this->id)->where('user_id', $user->id)->first()?->role;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
