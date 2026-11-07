<?php

namespace App\Models\Projects;

use App\Enums\Projects\CycleStatus;
use App\Models\Team;
use Carbon\CarbonInterface;
use Database\Factories\Projects\CycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A time-boxed planning period of one team. Workstreams, not issues, belong to cycles so each team
 * schedules its own part of a shared issue independently.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property int $number
 * @property string|null $name
 * @property string|null $goals
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property CycleStatus $status
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property int $workstreams_count
 * @property int $completed_workstreams_count
 * @property int $carried_over_count
 * @property array<int, array<string, mixed>>|null $burndown
 * @property-read Team $team
 * @property-read Collection<int, IssueWorkstream> $workstreams
 */
#[Fillable(['team_id', 'number', 'name', 'goals', 'starts_at', 'ends_at', 'status', 'started_at', 'completed_at', 'burndown'])]
class Cycle extends Model
{
    /** @use HasFactory<CycleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (Cycle $cycle) => $cycle->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<IssueWorkstream, $this> */
    public function workstreams(): HasMany
    {
        return $this->hasMany(IssueWorkstream::class);
    }

    public function displayName(): string
    {
        return $this->name ?: 'Cycle '.$this->number;
    }

    protected function casts(): array
    {
        return [
            'status' => CycleStatus::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'burndown' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
