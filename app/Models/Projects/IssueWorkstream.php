<?php

namespace App\Models\Projects;

use App\Enums\Projects\StatusCategory;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Projects\IssueWorkstreamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One team's participation in an issue: its own responsibility, status (from the team's
 * workflow), assignees, estimate, cycle and dependencies.
 *
 * @property int $id
 * @property string $uuid
 * @property int $issue_id
 * @property int $team_id
 * @property string|null $responsibility
 * @property int $status_id
 * @property StatusCategory $status_category
 * @property string|null $estimate
 * @property CarbonInterface|null $due_date
 * @property bool $is_required
 * @property int|null $cycle_id
 * @property int $blocked_by_count
 * @property int $position
 * @property string|null $sort_order
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Issue $issue
 * @property-read Team $team
 * @property-read WorkflowStatus $status
 * @property-read Cycle|null $cycle
 * @property-read Collection<int, User> $assignees
 * @property-read Collection<int, WorkstreamDependency> $dependencies
 * @property-read Collection<int, WorkstreamDependency> $dependents
 */
#[Fillable(['issue_id', 'team_id', 'responsibility', 'status_id', 'status_category', 'estimate', 'due_date', 'is_required', 'cycle_id', 'position', 'sort_order', 'started_at', 'completed_at'])]
class IssueWorkstream extends Model
{
    /** @use HasFactory<IssueWorkstreamFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (IssueWorkstream $workstream) => $workstream->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<WorkflowStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(WorkflowStatus::class, 'status_id');
    }

    /** @return BelongsTo<Cycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'issue_workstream_assignees', 'workstream_id', 'user_id')->withPivot('assigned_by_id')->withTimestamps();
    }

    /** @return HasMany<WorkstreamDependency, $this> */
    public function dependencies(): HasMany
    {
        return $this->hasMany(WorkstreamDependency::class, 'workstream_id');
    }

    /** @return HasMany<WorkstreamDependency, $this> */
    public function dependents(): HasMany
    {
        return $this->hasMany(WorkstreamDependency::class, 'depends_on_workstream_id');
    }

    protected function casts(): array
    {
        return [
            'status_category' => StatusCategory::class,
            'is_required' => 'boolean',
            'due_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
