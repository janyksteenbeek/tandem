<?php

namespace App\Models\Projects;

use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Projects\ModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A functional area of a product (Reporting, CRM, Integrations → Gmail). Permanent, nestable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property string|null $color
 * @property int|null $owner_id
 * @property int $position
 * @property int $issues_count
 * @property int $open_issues_count
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Product $product
 * @property-read Module|null $parent
 * @property-read User|null $owner
 * @property-read Collection<int, Module> $children
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, Issue> $issues
 */
#[Fillable(['product_id', 'parent_id', 'name', 'slug', 'description', 'icon', 'color', 'owner_id', 'position'])]
class Module extends Model
{
    /** @use HasFactory<ModuleFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Module $module) {
            $module->uuid ??= (string) Str::uuid();

            if (empty($module->slug)) {
                $base = Str::slug($module->name) ?: 'module';
                $slug = $base;
                $i = 1;

                while (static::withTrashed()->where('product_id', $module->product_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.(++$i);
                }

                $module->slug = $slug;
            }
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Module, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'parent_id');
    }

    /** @return HasMany<Module, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Module::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'module_team')->withTimestamps();
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'module_project')->withTimestamps();
    }

    /** @return BelongsToMany<Issue, $this> */
    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'issue_module')->withTimestamps();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
