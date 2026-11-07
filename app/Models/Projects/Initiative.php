<?php

namespace App\Models\Projects;

use App\Enums\Projects\InitiativeStatus;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Projects\InitiativeFactory;
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
 * A strategic grouping of projects.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string|null $description_html
 * @property string $color
 * @property int|null $owner_id
 * @property InitiativeStatus $status
 * @property CarbonInterface|null $start_date
 * @property CarbonInterface|null $target_date
 * @property-read Organization $organization
 * @property-read User|null $owner
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, Milestone> $milestones
 */
#[Fillable(['organization_id', 'name', 'slug', 'description_html', 'color', 'owner_id', 'status', 'start_date', 'target_date'])]
class Initiative extends Model
{
    /** @use HasFactory<InitiativeFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Initiative $initiative) {
            $initiative->uuid ??= (string) Str::uuid();

            if (empty($initiative->slug)) {
                $base = Str::slug($initiative->name) ?: 'initiative';
                $slug = $base;
                $i = 1;

                while (static::withTrashed()->where('organization_id', $initiative->organization_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.(++$i);
                }

                $initiative->slug = $slug;
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

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'initiative_project')->withPivot('position')->withTimestamps()->orderByPivot('position');
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'status' => InitiativeStatus::class,
            'start_date' => 'date',
            'target_date' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
