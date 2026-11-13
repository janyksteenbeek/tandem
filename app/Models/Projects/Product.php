<?php

namespace App\Models\Projects;

use App\Enums\Projects\ProductStatus;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Projects\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * A long-lived application, service or platform the organization builds. Its key prefixes every
 * issue identifier (FRONT-124).
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string $key
 * @property string|null $description
 * @property string|null $icon
 * @property string $color
 * @property int|null $owner_id
 * @property ProductStatus $status
 * @property int|null $issue_workflow_id
 * @property int $issues_count
 * @property int $open_issues_count
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Organization $organization
 * @property-read User|null $owner
 * @property-read Workflow|null $issueWorkflow
 * @property-read Collection<int, Module> $modules
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, Release> $releases
 * @property-read Collection<int, Issue> $issues
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, IssueLabel> $labels
 * @property-read Collection<int, EntityLink> $links
 */
#[Fillable(['organization_id', 'name', 'slug', 'key', 'description', 'icon', 'color', 'owner_id', 'status', 'issue_workflow_id'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            $product->uuid ??= (string) Str::uuid();
            $product->key = strtoupper($product->key);

            if (empty($product->slug)) {
                $base = Str::slug($product->name) ?: 'product';
                $slug = $base;
                $i = 1;

                while (static::withTrashed()->where('organization_id', $product->organization_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.(++$i);
                }

                $product->slug = $slug;
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
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Workflow, $this> */
    public function issueWorkflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'issue_workflow_id');
    }

    /** @return HasMany<Module, $this> */
    public function modules(): HasMany
    {
        return $this->hasMany(Module::class)->orderBy('position')->orderBy('name');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Release, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class)->orderByDesc('planned_at');
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /** @return HasMany<IssueLabel, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(IssueLabel::class)->orderBy('name');
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'product_team')->withTimestamps();
    }

    /** @return BelongsToMany<User, $this, ProductMember, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'product_members')
            ->using(ProductMember::class)
            ->as('membership')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /** @return MorphMany<EntityLink, $this> */
    public function links(): MorphMany
    {
        return $this->morphMany(EntityLink::class, 'linkable')->orderBy('position');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active->value);
    }

    protected function casts(): array
    {
        return ['status' => ProductStatus::class];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
