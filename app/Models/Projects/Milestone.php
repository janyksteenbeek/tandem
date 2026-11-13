<?php

namespace App\Models\Projects;

use App\Enums\Projects\MilestoneStatus;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Database\Factories\Projects\MilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int|null $project_id
 * @property int|null $initiative_id
 * @property string $name
 * @property string|null $description
 * @property CarbonInterface|null $target_date
 * @property MilestoneStatus $status
 * @property int $position
 * @property int $issues_count
 * @property int $completed_issues_count
 * @property CarbonInterface|null $completed_at
 * @property-read Organization $organization
 * @property-read Project|null $project
 * @property-read Initiative|null $initiative
 * @property-read Collection<int, Issue> $issues
 */
#[Fillable(['organization_id', 'project_id', 'initiative_id', 'name', 'description', 'target_date', 'status', 'position', 'completed_at'])]
class Milestone extends Model
{
    /** @use HasFactory<MilestoneFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (Milestone $milestone) => $milestone->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Initiative, $this> */
    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class);
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    protected function casts(): array
    {
        return [
            'status' => MilestoneStatus::class,
            'target_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
