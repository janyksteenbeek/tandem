<?php

namespace App\Services\Projects;

use App\Http\Resources\Projects\IssueListItemResource;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the reports pages. Issue metrics always count distinct issues; team metrics
 * count workstreams and are labelled as such, so an issue three teams work on is still one
 * issue in every organization, product and project figure.
 */
class Reporting
{
    public function __construct(protected IssueAccess $access, protected ProjectProgress $progress, protected CycleScheduler $cycles) {}

    /**
     * @return array<string, mixed>
     */
    public function organization(Organization $organization, User $user, ?CarbonInterface $since = null): array
    {
        $since ??= now()->subDays(30);
        $visible = fn (): Builder => Issue::query()->visibleTo($user);

        return [
            'issues' => $this->issueFigures($visible, $since),
            'throughput' => $this->throughput($visible, $since),
            'teams' => $this->teamFigures($organization->teams()->get(), $visible),
            'cross_team' => $this->crossTeam($visible),
            'projects' => $this->projectFigures(Project::query()->tap(fn ($q) => $this->access->applyProjectVisibility($q, $user, $organization->id))->with(['lead', 'product'])->get()),
            'overdue' => $this->overdue($visible),
            'since' => $since->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function product(Product $product, User $user, ?CarbonInterface $since = null): array
    {
        $since ??= now()->subDays(30);
        $visible = fn (): Builder => Issue::query()->visibleTo($user)->where('issues.product_id', $product->id);

        return [
            'issues' => $this->issueFigures($visible, $since),
            'throughput' => $this->throughput($visible, $since),
            'by_module' => DB::table('issue_module')
                ->join('issues', 'issues.id', '=', 'issue_module.issue_id')
                ->join('modules', 'modules.id', '=', 'issue_module.module_id')
                ->where('modules.product_id', $product->id)
                ->whereNull('issues.deleted_at')
                ->selectRaw('modules.id, modules.name, modules.color, SUM(issues.status_category NOT IN ("completed", "canceled")) AS open, SUM(issues.status_category = "completed") AS completed')
                ->groupBy('modules.id', 'modules.name', 'modules.color')
                ->get()
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'color' => $r->color, 'open' => (int) $r->open, 'completed' => (int) $r->completed])
                ->values()->all(),
            'projects' => $this->projectFigures($product->projects()->tap(fn ($q) => $this->access->applyProjectVisibility($q, $user, $product->organization_id))->with(['lead', 'product'])->get()),
            'releases' => $product->releases()->orderBy('planned_at')->get()->map(fn ($r) => ['uuid' => $r->uuid, 'name' => $r->name, 'status' => $r->status->value, 'planned_at' => $r->planned_at?->toDateString(), 'issues_count' => $r->issues_count, 'completed_issues_count' => $r->completed_issues_count])->values()->all(),
            'cross_team' => $this->crossTeam($visible),
            'since' => $since->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function project(Project $project, User $user, ?CarbonInterface $since = null): array
    {
        $since ??= now()->subDays(30);
        $visible = fn (): Builder => Issue::query()->visibleTo($user)->where('issues.project_id', $project->id);
        $progress = $this->progress->recompute($project);

        return [
            'progress' => $progress,
            'issues' => $this->issueFigures($visible, $since),
            'throughput' => $this->throughput($visible, $since),
            'milestones' => $project->milestones()->get()->map(fn ($m) => ['uuid' => $m->uuid, 'name' => $m->name, 'target_date' => $m->target_date?->toDateString(), 'status' => $m->status->value, 'issues_count' => $m->issues_count, 'completed_issues_count' => $m->completed_issues_count])->values()->all(),
            'blockers' => (clone $visible())->whereNotIn('status_category', ['completed', 'canceled'])->where(fn ($q) => $q->where('blocked_by_count', '>', 0)->orWhere('blocked_workstreams_count', '>', 0))->count(),
            'target' => [
                'date' => $project->target_date?->toDateString(),
                'days_left' => $project->target_date ? (int) now()->startOfDay()->diffInDays($project->target_date, false) : null,
                'on_track' => $project->target_date ? $this->onTrack($progress, $project) : null,
            ],
            'cross_team' => $this->crossTeam($visible),
            'since' => $since->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function team(Team $team, User $user, ?CarbonInterface $since = null): array
    {
        $since ??= now()->subDays(30);

        $open = DB::table('issue_workstreams')->join('issues', 'issues.id', '=', 'issue_workstreams.issue_id')->whereNull('issues.deleted_at')->where('issue_workstreams.team_id', $team->id);

        $completed = (clone $open)->where('issue_workstreams.status_category', 'completed')->where('issue_workstreams.completed_at', '>=', $since);

        $leadTime = (clone $completed)->whereNotNull('issue_workstreams.started_at')->selectRaw('AVG(TIMESTAMPDIFF(HOUR, issue_workstreams.created_at, issue_workstreams.completed_at)) AS lead, AVG(TIMESTAMPDIFF(HOUR, issue_workstreams.started_at, issue_workstreams.completed_at)) AS cycle')->first();

        return [
            'workstreams' => [
                'open' => (clone $open)->whereNotIn('issue_workstreams.status_category', ['completed', 'canceled'])->count(),
                'started' => (clone $open)->where('issue_workstreams.status_category', 'started')->count(),
                'blocked' => (clone $open)->where('issue_workstreams.blocked_by_count', '>', 0)->whereNotIn('issue_workstreams.status_category', ['completed', 'canceled'])->count(),
                'completed_since' => (clone $completed)->count(),
            ],
            'lead_time_hours' => $leadTime?->lead !== null ? round((float) $leadTime->lead, 1) : null,
            'cycle_time_hours' => $leadTime?->cycle !== null ? round((float) $leadTime->cycle, 1) : null,
            'velocity' => $this->cycles->velocity($team, 8),
            'workload' => DB::table('issue_workstream_assignees')
                ->join('issue_workstreams', 'issue_workstreams.id', '=', 'issue_workstream_assignees.workstream_id')
                ->join('users', 'users.id', '=', 'issue_workstream_assignees.user_id')
                ->where('issue_workstreams.team_id', $team->id)
                ->whereNotIn('issue_workstreams.status_category', ['completed', 'canceled'])
                ->selectRaw('users.id, users.name, COUNT(*) AS open, SUM(COALESCE(issue_workstreams.estimate, 0)) AS points')
                ->groupBy('users.id', 'users.name')
                ->get()
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'open' => (int) $r->open, 'points' => (float) $r->points])
                ->values()->all(),
            'completed_per_week' => (clone $open)->where('issue_workstreams.status_category', 'completed')->where('issue_workstreams.completed_at', '>=', now()->subWeeks(8))
                ->selectRaw('YEARWEEK(issue_workstreams.completed_at, 3) AS week, COUNT(*) AS aggregate')
                ->groupBy('week')->orderBy('week')->get()
                ->map(fn ($r) => ['week' => (string) $r->week, 'completed' => (int) $r->aggregate])->values()->all(),
            'waiting_on_others' => (clone $open)->whereNotIn('issue_workstreams.status_category', ['completed', 'canceled'])->where('issue_workstreams.blocked_by_count', '>', 0)->count(),
            'since' => $since->toDateString(),
        ];
    }

    /**
     * Figures about work spanning several teams. Issue counts are distinct issues; handoff time
     * is the average gap between a workstream finishing and its dependent workstream starting.
     *
     * @param  callable(): Builder<Issue>  $visible
     * @return array<string, mixed>
     */
    public function crossTeam(callable $visible): array
    {
        $multi = (clone $visible())->where('issues.workstreams_count', '>', 1);

        $handoff = DB::table('workstream_dependencies AS d')
            ->join('issue_workstreams AS from_ws', 'from_ws.id', '=', 'd.depends_on_workstream_id')
            ->join('issue_workstreams AS to_ws', 'to_ws.id', '=', 'd.workstream_id')
            ->whereColumn('from_ws.issue_id', 'to_ws.issue_id')
            ->whereNotNull('d.resolved_at')
            ->whereNotNull('to_ws.started_at')
            ->whereIn('to_ws.issue_id', (clone $visible())->select('issues.id'))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, d.resolved_at, to_ws.started_at)) AS hours, COUNT(*) AS handoffs')
            ->first();

        $perTeam = DB::table('issue_workstreams')
            ->join('issues', 'issues.id', '=', 'issue_workstreams.issue_id')
            ->join('teams', 'teams.id', '=', 'issue_workstreams.team_id')
            ->whereIn('issues.id', (clone $multi)->select('issues.id'))
            ->selectRaw('teams.id, teams.name, teams.color, COUNT(*) AS workstreams, SUM(issue_workstreams.status_category = "completed") AS completed, SUM(issue_workstreams.blocked_by_count > 0 AND issue_workstreams.status_category NOT IN ("completed", "canceled")) AS blocked')
            ->groupBy('teams.id', 'teams.name', 'teams.color')
            ->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'color' => $r->color, 'workstreams' => (int) $r->workstreams, 'completed' => (int) $r->completed, 'blocked' => (int) $r->blocked])
            ->values()->all();

        return [
            'multi_team_issues' => (clone $multi)->count(),
            'multi_team_open' => (clone $multi)->whereNotIn('issues.status_category', ['completed', 'canceled'])->count(),
            'blocked_by_another_team' => (clone $visible())->where('issues.blocked_workstreams_count', '>', 0)->whereNotIn('issues.status_category', ['completed', 'canceled'])->count(),
            'waiting_workstreams' => DB::table('issue_workstreams')->whereIn('issue_id', (clone $visible())->select('issues.id'))->where('blocked_by_count', '>', 0)->whereNotIn('status_category', ['completed', 'canceled'])->count(),
            'avg_handoff_hours' => $handoff?->hours !== null ? round((float) $handoff->hours, 1) : null,
            'handoffs' => (int) ($handoff->handoffs ?? 0),
            'per_team' => $perTeam,
            'ready_to_complete' => (clone $visible())->where('issues.is_ready_to_complete', true)->whereNotIn('issues.status_category', ['completed', 'canceled'])->count(),
        ];
    }

    /**
     * @param  callable(): Builder<Issue>  $visible
     * @return array<string, int>
     */
    protected function issueFigures(callable $visible, CarbonInterface $since): array
    {
        $rows = (clone $visible())->reorder()->selectRaw('issues.status_category, COUNT(DISTINCT issues.id) AS aggregate')->groupBy('issues.status_category')->pluck('aggregate', 'status_category');

        return [
            'backlog' => (int) ($rows['backlog'] ?? 0),
            'unstarted' => (int) ($rows['unstarted'] ?? 0),
            'started' => (int) ($rows['started'] ?? 0),
            'completed' => (int) ($rows['completed'] ?? 0),
            'canceled' => (int) ($rows['canceled'] ?? 0),
            'open' => (int) (($rows['backlog'] ?? 0) + ($rows['unstarted'] ?? 0) + ($rows['started'] ?? 0)),
            'completed_since' => (clone $visible())->where('issues.status_category', 'completed')->where('issues.completed_at', '>=', $since)->count(),
            'created_since' => (clone $visible())->where('issues.created_at', '>=', $since)->count(),
        ];
    }

    /**
     * Created vs completed per week over the last eight weeks.
     *
     * @param  callable(): Builder<Issue>  $visible
     * @return array<int, array{week: string, created: int, completed: int}>
     */
    protected function throughput(callable $visible, CarbonInterface $since): array
    {
        $from = now()->subWeeks(8)->startOfWeek();

        $created = (clone $visible())->reorder()->where('issues.created_at', '>=', $from)->selectRaw('YEARWEEK(issues.created_at, 3) AS week, COUNT(DISTINCT issues.id) AS aggregate')->groupBy('week')->pluck('aggregate', 'week');
        $completed = (clone $visible())->reorder()->where('issues.completed_at', '>=', $from)->selectRaw('YEARWEEK(issues.completed_at, 3) AS week, COUNT(DISTINCT issues.id) AS aggregate')->groupBy('week')->pluck('aggregate', 'week');

        $weeks = [];
        $cursor = $from->copy();

        while ($cursor->lte(now())) {
            $key = $cursor->format('oW');
            $weeks[] = ['week' => $cursor->toDateString(), 'created' => (int) ($created[$key] ?? 0), 'completed' => (int) ($completed[$key] ?? 0)];
            $cursor = $cursor->addWeek();
        }

        return $weeks;
    }

    /**
     * @param  Collection<int, Team>  $teams
     * @param  callable(): Builder<Issue>  $visible
     * @return array<int, array<string, mixed>>
     */
    protected function teamFigures($teams, callable $visible): array
    {
        $rows = DB::table('issue_workstreams')
            ->whereIn('issue_id', (clone $visible())->select('issues.id'))
            ->selectRaw('team_id, SUM(status_category NOT IN ("completed", "canceled")) AS open, SUM(status_category = "started") AS started, SUM(blocked_by_count > 0 AND status_category NOT IN ("completed", "canceled")) AS blocked')
            ->groupBy('team_id')
            ->get()
            ->keyBy('team_id');

        return $teams->map(fn (Team $team) => [
            'id' => $team->id,
            'slug' => $team->slug,
            'name' => $team->name,
            'color' => $team->color,
            'open_workstreams' => (int) ($rows[$team->id]->open ?? 0),
            'started_workstreams' => (int) ($rows[$team->id]->started ?? 0),
            'blocked_workstreams' => (int) ($rows[$team->id]->blocked ?? 0),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>
     */
    protected function projectFigures($projects): array
    {
        return [
            'active' => $projects->filter(fn (Project $p) => in_array($p->status->value, ['planned', 'in_progress'], true))->count(),
            'at_risk' => $projects->filter(fn (Project $p) => in_array($p->health->value, ['at_risk', 'off_track'], true))->count(),
            'overdue' => $projects->filter(fn (Project $p) => $p->target_date && $p->target_date->isPast() && ! in_array($p->status->value, ['completed', 'canceled'], true))->count(),
            'rows' => $projects->map(fn (Project $p) => [
                'uuid' => $p->uuid, 'slug' => $p->slug, 'name' => $p->name, 'color' => $p->color, 'status' => $p->status->value, 'health' => $p->health->value,
                'target_date' => $p->target_date?->toDateString(), 'percent' => (float) ($p->progress['percent'] ?? 0), 'issues' => (int) ($p->progress['total'] ?? 0),
                'product' => $p->product ? ['key' => $p->product->key, 'name' => $p->product->name] : null,
            ])->values()->all(),
        ];
    }

    /**
     * @param  callable(): Builder<Issue>  $visible
     * @return array<string, mixed>
     */
    protected function overdue(callable $visible): array
    {
        $overdue = (clone $visible())->whereNotNull('issues.due_date')->where('issues.due_date', '<', now()->toDateString())->whereNotIn('issues.status_category', ['completed', 'canceled']);

        return [
            'count' => (clone $overdue)->count(),
            'issues' => IssueListItemResource::collection((clone $overdue)->with(IssueQuery::EAGER)->orderBy('issues.due_date')->limit(10)->get())->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    protected function onTrack(array $progress, Project $project): bool
    {
        if (! $project->target_date || ! $project->start_date) {
            return (float) ($progress['percent'] ?? 0) >= 50;
        }

        $total = max(1, $project->start_date->diffInDays($project->target_date));
        $elapsed = min($total, max(0, $project->start_date->diffInDays(now())));

        return (float) ($progress['percent'] ?? 0) >= ($elapsed / $total) * 100 - 10;
    }
}
