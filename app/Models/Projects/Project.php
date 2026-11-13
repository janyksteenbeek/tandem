<?php

namespace App\Models\Projects;

use App\Enums\Projects\ProjectHealth;
use App\Enums\Projects\ProjectStatus;
use App\Enums\Projects\ProjectVisibility;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Projects\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An initiative with a goal, a lead, a target date and the issues that deliver it. Optionally
 * belongs to a product; standalone projects cover internal organizational work.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int|null $product_id
 * @property string $name
 * @property string $slug
 * @property string|null $description_html
 * @property string|null $icon
 * @property string $color
 * @property int|null $lead_id
 * @property ProjectStatus $status
 * @property ProjectHealth $health
 * @property int $priority
 * @property ProjectVisibility $visibility
 * @property CarbonInterface|null $start_date
 * @property CarbonInterface|null $target_date
 * @property CarbonInterface|null $completed_at
 * @property int $issues_count
 * @property int $completed_issues_count
 * @property int $canceled_issues_count
 * @property int $started_issues_count
 * @property array<string, mixed>|null $progress
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Organization $organization
 * @property-read Product|null $product
 * @property-read User|null $lead
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Module> $modules
 * @property-read Collection<int, Milestone> $milestones
 * @property-read Collection<int, Issue> $issues
 * @property-read Collection<int, Initiative> $initiatives
 * @property-read Collection<int, EntityLink> $links
 */
#[Fillable(['organization_id', 'product_id', 'name', 'slug', 'description_html', 'icon', 'color', 'lead_id', 'status', 'health', 'priority', 'visibility', 'start_date', 'target_date', 'completed_at', 'progress'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->uuid ??= (string) Str::uuid();

            if (empty($project->slug)) {
                $base = Str::slug($project->name) ?: 'project';
                $slug = $base;
                $i = 1;

                while (static::withTrashed()->where('organization_id', $project->organization_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.(++$i);
                }

                $project->slug = $slug;
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'project_team')->withTimestamps();
    }

    /** @return BelongsToMany<User, $this, ProjectMember, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->using(ProjectMember::class)
            ->as('membership')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Module, $this> */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_project')->withTimestamps();
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('position')->orderBy('target_date');
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /** @return BelongsToMany<Initiative, $this> */
    public function initiatives(): BelongsToMany
    {
        return $this->belongsToMany(Initiative::class, 'initiative_project')->withPivot('position')->withTimestamps();
    }

    /** @return MorphMany<EntityLink, $this> */
    public function links(): MorphMany
    {
        return $this->morphMany(EntityLink::class, 'linkable')->orderBy('position');
    }

    public function isPrivate(): bool
    {
        return $this->visibility === ProjectVisibility::Private;
    }

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'health' => ProjectHealth::class,
            'visibility' => ProjectVisibility::class,
            'start_date' => 'date',
            'target_date' => 'date',
            'completed_at' => 'datetime',
            'progress' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
