<?php

namespace App\Services\Projects;

use App\Enums\Projects\CycleStatus;
use App\Events\Projects\CycleCompleted;
use App\Events\Projects\CycleStarted;
use App\Models\Projects\Cycle;
use App\Models\Projects\IssueWorkstream;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Projects\CycleStartedNotification;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Team cycles: numbering, automatic scheduling from the team's settings, starting, completing
 * with carry-over, and the burndown snapshot. Cycles hold workstreams, never whole issues, so
 * two teams can plan the same issue in different cycles.
 */
class CycleScheduler
{
    public function __construct(protected AuditLogger $audit, protected IssueActions $issues) {}

    /**
     * @param  array{name?: string|null, goals?: string|null, starts_at: string|CarbonInterface, ends_at: string|CarbonInterface}  $attributes
     */
    public function create(Team $team, array $attributes, ?User $actor): Cycle
    {
        $cycle = DB::transaction(function () use ($team, $attributes) {
            $locked = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $number = (int) Cycle::query()->where('team_id', $locked->id)->max('number') + 1;

            return Cycle::query()->create([
                'team_id' => $locked->id,
                'number' => $number,
                'name' => $attributes['name'] ?? null,
                'goals' => $attributes['goals'] ?? null,
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
                'status' => CycleStatus::Upcoming,
            ]);
        });

        $this->audit->record('cycle.created', $team->organization, $actor, $cycle, ['team_id' => $team->id, 'number' => $cycle->number]);

        return $cycle;
    }

    /**
     * @param  array{name?: string|null, goals?: string|null, starts_at?: string|null, ends_at?: string|null}  $attributes
     */
    public function update(Cycle $cycle, array $attributes, ?User $actor): Cycle
    {
        $cycle->fill(array_intersect_key($attributes, array_flip(['name', 'goals', 'starts_at', 'ends_at'])))->save();

        if ($cycle->ends_at->lt($cycle->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => 'A cycle must end after it starts.']);
        }

        $this->audit->record('cycle.updated', $cycle->team->organization, $actor, $cycle);

        return $cycle;
    }

    /**
     * Keep `upcoming_count` future cycles scheduled for a team that has cycles enabled.
     *
     * @return Collection<int, Cycle>
     */
    public function ensureUpcoming(Team $team, ?CarbonInterface $now = null): Collection
    {
        $settings = $team->cycleSettings();
        $now = ($now ?? now())->copy()->startOfDay();

        if (! $settings['enabled']) {
            return new Collection;
        }

        $upcoming = Cycle::query()->where('team_id', $team->id)->where('status', CycleStatus::Upcoming->value)->orderBy('starts_at')->get();
        $last = Cycle::query()->where('team_id', $team->id)->whereIn('status', [CycleStatus::Active->value, CycleStatus::Upcoming->value])->orderByDesc('ends_at')->first();

        $cursor = $last ? $last->ends_at->copy()->addDay()->addWeeks($settings['cooldown_weeks']) : $now->copy()->next($settings['start_weekday'] === 0 ? CarbonInterface::SUNDAY : $settings['start_weekday']);

        if (! $last && $cursor->isAfter($now->copy()->addDays(6))) {
            $cursor = $now->copy();
        }

        while ($upcoming->count() < $settings['upcoming_count']) {
            $ends = $cursor->copy()->addWeeks($settings['length_weeks'])->subDay();
            $upcoming->push($this->create($team, ['starts_at' => $cursor->toDateString(), 'ends_at' => $ends->toDateString()], null));
            $cursor = $ends->copy()->addDay()->addWeeks($settings['cooldown_weeks']);
        }

        return $upcoming;
    }

    public function start(Cycle $cycle, ?User $actor): Cycle
    {
        if ($cycle->status === CycleStatus::Active) {
            return $cycle;
        }

        if ($cycle->status === CycleStatus::Completed) {
            throw ValidationException::withMessages(['cycle' => 'A completed cycle cannot be started again.']);
        }

        DB::transaction(function () use ($cycle) {
            Cycle::query()->where('team_id', $cycle->team_id)->where('status', CycleStatus::Active->value)->whereKeyNot($cycle->id)->get()
                ->each(fn (Cycle $active) => $this->complete($active, null, $cycle));

            $cycle->forceFill(['status' => CycleStatus::Active, 'started_at' => now()])->save();
            $this->refreshCounters($cycle);
            $this->snapshot($cycle);
        });

        CycleStarted::dispatch($cycle);
        Notification::send($cycle->team->members()->get(), new CycleStartedNotification($cycle));
        $this->audit->record('cycle.started', $cycle->team->organization, $actor, $cycle);

        return $cycle;
    }

    /**
     * Close a cycle. Open workstreams move to the next cycle (created if needed) when carrying
     * over, otherwise they drop back to the backlog.
     */
    public function complete(Cycle $cycle, ?User $actor, ?Cycle $next = null, ?bool $carryOver = null): Cycle
    {
        if ($cycle->status === CycleStatus::Completed) {
            return $cycle;
        }

        $carryOver ??= $cycle->team->cycleSettings()['auto_carry_over'];
        $carried = 0;

        DB::transaction(function () use ($cycle, $actor, $next, $carryOver, &$carried) {
            $open = IssueWorkstream::query()->where('cycle_id', $cycle->id)->whereNotIn('status_category', ['completed', 'canceled'])->with(['issue', 'team'])->get();

            if ($open->isNotEmpty()) {
                $target = $carryOver ? ($next ?? $this->nextCycle($cycle)) : null;

                foreach ($open as $workstream) {
                    $this->issues->setWorkstreamCycle($workstream, $target, $actor, 'carry_over');
                    $carried++;
                }
            }

            $this->refreshCounters($cycle);
            $cycle->forceFill(['status' => CycleStatus::Completed, 'completed_at' => now(), 'carried_over_count' => $carried])->save();
            $this->snapshot($cycle);
        });

        CycleCompleted::dispatch($cycle, $carried);
        $this->audit->record('cycle.completed', $cycle->team->organization, $actor, $cycle, ['carried_over' => $carried]);

        return $cycle;
    }

    public function delete(Cycle $cycle, ?User $actor): void
    {
        if ($cycle->status === CycleStatus::Active) {
            throw ValidationException::withMessages(['cycle' => 'Complete the cycle before deleting it.']);
        }

        DB::transaction(function () use ($cycle) {
            IssueWorkstream::query()->where('cycle_id', $cycle->id)->update(['cycle_id' => null]);
            $cycle->delete();
        });

        $this->audit->record('cycle.deleted', $cycle->team->organization, $actor, $cycle);
    }

    /**
     * Runs hourly: starts cycles whose day has come, completes the ones that ended, and keeps the
     * upcoming ones scheduled.
     *
     * @return array{started: int, completed: int, scheduled: int}
     */
    public function tick(?CarbonInterface $now = null): array
    {
        $now = ($now ?? now())->copy();
        $today = $now->toDateString();
        $stats = ['started' => 0, 'completed' => 0, 'scheduled' => 0];

        Cycle::query()->where('status', CycleStatus::Active->value)->whereDate('ends_at', '<', $today)->with('team')->get()
            ->each(function (Cycle $cycle) use (&$stats) {
                $this->complete($cycle, null);
                $stats['completed']++;
            });

        Cycle::query()->where('status', CycleStatus::Upcoming->value)->whereDate('starts_at', '<=', $today)->whereDate('ends_at', '>=', $today)->with('team')->get()
            ->each(function (Cycle $cycle) use (&$stats) {
                if ($cycle->team->cycleSettings()['enabled']) {
                    $this->start($cycle, null);
                    $stats['started']++;
                }
            });

        Team::query()->whereNotNull('cycle_settings')->get()->each(function (Team $team) use ($now, &$stats) {
            $stats['scheduled'] += $this->ensureUpcoming($team, $now)->count();
        });

        Cycle::query()->where('status', CycleStatus::Active->value)->get()->each(fn (Cycle $cycle) => $this->snapshot($cycle));

        return $stats;
    }

    /**
     * Counts and the daily burndown point.
     *
     * @return array<string, mixed>
     */
    public function summary(Cycle $cycle): array
    {
        $this->refreshCounters($cycle);

        $rows = IssueWorkstream::query()->where('cycle_id', $cycle->id)
            ->selectRaw('status_category, COUNT(*) AS aggregate, SUM(COALESCE(estimate, 0)) AS points')
            ->groupBy('status_category')
            ->get()
            ->keyBy('status_category');

        $count = fn (string $c) => (int) ($rows[$c]->aggregate ?? 0);
        $points = fn (string $c) => (float) ($rows[$c]->points ?? 0);
        $total = array_sum(array_map($count, ['backlog', 'unstarted', 'started', 'completed', 'canceled']));

        return [
            'total' => $total,
            'completed' => $count('completed'),
            'started' => $count('started'),
            'canceled' => $count('canceled'),
            'remaining' => $total - $count('completed') - $count('canceled'),
            'points_total' => array_sum(array_map($points, ['backlog', 'unstarted', 'started', 'completed'])),
            'points_completed' => $points('completed'),
            'days_total' => $cycle->starts_at->diffInDays($cycle->ends_at) + 1,
            'days_left' => max(0, (int) now()->startOfDay()->diffInDays($cycle->ends_at, false) + 1),
            'burndown' => $cycle->burndown ?? [],
        ];
    }

    /**
     * Velocity over the last completed cycles of the team.
     *
     * @return array<int, array{cycle: array<string, mixed>, committed: int, completed: int, points: float}>
     */
    public function velocity(Team $team, int $limit = 6): array
    {
        return Cycle::query()->where('team_id', $team->id)->where('status', CycleStatus::Completed->value)->orderByDesc('number')->limit($limit)->get()
            ->reverse()
            ->values()
            ->map(fn (Cycle $cycle) => [
                'cycle' => ['id' => $cycle->id, 'uuid' => $cycle->uuid, 'number' => $cycle->number, 'name' => $cycle->displayName()],
                'committed' => $cycle->workstreams_count,
                'completed' => $cycle->completed_workstreams_count,
                'points' => (float) IssueWorkstream::query()->where('cycle_id', $cycle->id)->where('status_category', 'completed')->sum('estimate'),
            ])
            ->all();
    }

    protected function nextCycle(Cycle $cycle): Cycle
    {
        $next = Cycle::query()->where('team_id', $cycle->team_id)->where('status', CycleStatus::Upcoming->value)->where('starts_at', '>', $cycle->starts_at)->orderBy('starts_at')->first();

        if ($next) {
            return $next;
        }

        $settings = $cycle->team->cycleSettings();
        $starts = $cycle->ends_at->copy()->addDay()->addWeeks($settings['cooldown_weeks']);

        return $this->create($cycle->team, ['starts_at' => $starts->toDateString(), 'ends_at' => $starts->copy()->addWeeks($settings['length_weeks'])->subDay()->toDateString()], null);
    }

    public function refreshCounters(Cycle $cycle): void
    {
        $cycle->forceFill([
            'workstreams_count' => IssueWorkstream::query()->where('cycle_id', $cycle->id)->count(),
            'completed_workstreams_count' => IssueWorkstream::query()->where('cycle_id', $cycle->id)->where('status_category', 'completed')->count(),
        ])->saveQuietly();
    }

    /**
     * One burndown point per day: remaining and scope at that moment.
     */
    protected function snapshot(Cycle $cycle): void
    {
        $today = now()->toDateString();
        $points = collect($cycle->burndown ?? [])->reject(fn (array $p) => ($p['date'] ?? null) === $today)->values();

        $total = IssueWorkstream::query()->where('cycle_id', $cycle->id)->whereNot('status_category', 'canceled')->count();
        $done = IssueWorkstream::query()->where('cycle_id', $cycle->id)->where('status_category', 'completed')->count();

        $points->push(['date' => $today, 'scope' => $total, 'remaining' => $total - $done]);

        $cycle->forceFill(['burndown' => $points->sortBy('date')->values()->all()])->saveQuietly();
    }
}
