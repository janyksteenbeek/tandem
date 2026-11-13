<?php

namespace App\Models;

use App\Enums\TeamRole;
use App\Models\Mail\Inbox;
use App\Models\Projects\Cycle;
use App\Models\Projects\IssueWorkstream;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Projects\Workflow;
use Carbon\CarbonInterface;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property string|null $description
 * @property string|null $icon
 * @property array<string, mixed>|null $cycle_settings
 * @property string $estimate_scale
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Organization $organization
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Inbox> $inboxes
 * @property-read Workflow|null $workflow
 * @property-read Collection<int, Cycle> $cycles
 * @property-read Collection<int, IssueWorkstream> $workstreams
 * @property-read Collection<int, Product> $products
 * @property-read Collection<int, Project> $projects
 */
#[Fillable(['organization_id', 'name', 'slug', 'color', 'icon', 'description', 'cycle_settings', 'estimate_scale'])]
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

    /** @return HasOne<Workflow, $this> */
    public function workflow(): HasOne
    {
        return $this->hasOne(Workflow::class);
    }

    /** @return HasMany<Cycle, $this> */
    public function cycles(): HasMany
    {
        return $this->hasMany(Cycle::class)->orderByDesc('number');
    }

    /** @return HasMany<IssueWorkstream, $this> */
    public function workstreams(): HasMany
    {
        return $this->hasMany(IssueWorkstream::class);
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_team')->withTimestamps();
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_team')->withTimestamps();
    }

    /**
     * @return array{enabled: bool, length_weeks: int, cooldown_weeks: int, start_weekday: int, upcoming_count: int, auto_carry_over: bool}
     */
    public function cycleSettings(): array
    {
        $settings = $this->cycle_settings ?? [];

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'length_weeks' => max(1, (int) ($settings['length_weeks'] ?? 2)),
            'cooldown_weeks' => max(0, (int) ($settings['cooldown_weeks'] ?? 0)),
            'start_weekday' => (int) ($settings['start_weekday'] ?? 1),
            'upcoming_count' => max(1, (int) ($settings['upcoming_count'] ?? 2)),
            'auto_carry_over' => (bool) ($settings['auto_carry_over'] ?? true),
        ];
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function roleOf(User $user): ?TeamRole
    {
        return TeamMember::query()->where('team_id', $this->id)->where('user_id', $user->id)->first()?->role;
    }

    protected function casts(): array
    {
        return ['cycle_settings' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
