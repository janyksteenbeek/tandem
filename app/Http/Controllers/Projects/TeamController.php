<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\Concerns\BuildsIssueLists;
use App\Http\Resources\Projects\CycleResource;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Http\Resources\Projects\ProjectSummaryResource;
use App\Http\Resources\Projects\TeamSummaryResource;
use App\Http\Resources\Projects\WorkflowResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Projects\Access\IssueAccess;
use App\Services\Projects\IssueLookups;
use App\Services\Projects\IssueQuery;
use App\Services\Projects\RecentlyViewed;
use App\Services\Projects\WorkflowActions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The team workspace: every issue the team participates in, shown with that team's own
 * workstream status.
 */
class TeamController extends Controller
{
    use BuildsIssueLists;

    public const TABS = ['overview', 'issues', 'backlog', 'board', 'cycles', 'workload', 'settings'];

    public function __construct(
        protected IssueLookups $lookups,
        protected IssueAccess $access,
        protected WorkflowActions $workflows,
        protected RecentlyViewed $recent,
        protected AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $currentOrganization): Response
    {
        $user = $request->user();
        $teamIds = $this->access->teamIds($user, $currentOrganization->id);

        $teams = $currentOrganization->teams()->with(['members', 'cycles' => fn ($q) => $q->where('status', 'active')])->get();

        $openByTeam = IssueWorkstream::query()
            ->selectRaw('team_id, COUNT(*) AS aggregate')
            ->whereIn('team_id', $teams->pluck('id'))
            ->whereNotIn('status_category', ['completed', 'canceled'])
            ->groupBy('team_id')
            ->pluck('aggregate', 'team_id');

        return Inertia::render('projects/teams/Index', [
            'teams' => $teams->map(fn (Team $team) => [
                ...TeamSummaryResource::make($team)->resolve(),
                'description' => $team->description,
                'is_member' => in_array($team->id, $teamIds, true),
                'members' => UserSummaryResource::collection($team->members->take(6))->resolve(),
                'members_count' => $team->members->count(),
                'open_workstreams' => (int) ($openByTeam[$team->id] ?? 0),
                'active_cycle' => $team->cycles->first() ? CycleResource::make($team->cycles->first())->resolve() : null,
            ])->values()->all(),
        ]);
    }

    public function show(Request $request, Organization $currentOrganization, Team $team, string $tab = 'overview'): Response
    {
        abort_unless($team->organization_id === $currentOrganization->id, 404);
        $this->authorize('view', $team);
        $user = $request->user();
        $tab = in_array($tab, self::TABS, true) ? $tab : 'overview';

        if ($tab === 'settings') {
            $this->authorize('lead', $team);
        }

        $this->recent->touch($user, $currentOrganization, $team);
        $request->attributes->set('focus_team_id', $team->id);

        $workflow = $this->workflows->forTeam($team);
        $display = $this->display($request, ['group_by' => 'status', 'mode' => $tab === 'board' ? 'board' : 'list']);
        $filters = $this->filters($request, [['field' => 'team', 'op' => 'in', 'value' => [$team->id]]]);

        if ($tab === 'backlog') {
            $filters['groups'][] = ['match' => 'all', 'clauses' => [['field' => 'cycle', 'op' => 'is_empty', 'value' => null]]];
        }

        $activeCycle = $team->cycles()->where('status', 'active')->first();

        return Inertia::render('projects/teams/Show', [
            'team' => [
                ...TeamSummaryResource::make($team)->resolve(),
                'description' => $team->description,
                'members' => $team->members()->get()->map(fn ($m) => [...UserSummaryResource::make($m)->resolve(), 'role' => $m->getRelation('membership')->role->value])->values()->all(),
                'is_member' => in_array($team->id, $this->access->teamIds($user, $currentOrganization->id), true),
                'cycle_settings' => $team->cycleSettings(),
                'estimate_scale' => $team->estimate_scale,
                'active_cycle' => $activeCycle ? CycleResource::make($activeCycle)->resolve() : null,
                'can' => ['lead' => $user->can('lead', $team), 'manage' => $user->can('manage', $team)],
            ],
            'tab' => $tab,
            'workflow' => WorkflowResource::make($workflow)->resolve(),
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
            'filters' => $filters,
            'display' => $display,
            'overview' => $this->tabProp($tab, 'overview', fn () => $this->overview($team, $user)),
            'issues' => in_array($tab, ['issues', 'backlog'], true) ? $this->issuesProp($request, $user, $filters, $display) : Inertia::optional(fn () => null),
            'board' => $this->tabProp($tab, 'board', fn () => $this->boardColumns($request, $user, $filters, $display, $workflow->statuses, $team->id)),
            'cycles' => $this->tabProp($tab, 'cycles', fn () => CycleResource::collection($team->cycles()->get())->resolve()),
            'workload' => $this->tabProp($tab, 'workload', fn () => $this->workload($team)),
            'projects' => $this->tabProp($tab, 'overview', fn () => ProjectSummaryResource::collection(
                $team->projects()->tap(fn ($q) => $this->access->applyProjectVisibility($q, $user, $currentOrganization->id))->with(['lead', 'teams', 'product'])->get()
            )->resolve()),
        ]);
    }

    public function updateSettings(Request $request, Organization $currentOrganization, Team $team): RedirectResponse|JsonResponse
    {
        abort_unless($team->organization_id === $currentOrganization->id, 404);
        $this->authorize('lead', $team);

        $data = $request->validate([
            'estimate_scale' => ['nullable', Rule::in(['none', 'fibonacci', 'linear', 'tshirt'])],
            'cycle_settings' => ['nullable', 'array'],
            'cycle_settings.enabled' => ['nullable', 'boolean'],
            'cycle_settings.length_weeks' => ['nullable', 'integer', 'min:1', 'max:12'],
            'cycle_settings.cooldown_weeks' => ['nullable', 'integer', 'min:0', 'max:4'],
            'cycle_settings.start_weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'cycle_settings.upcoming_count' => ['nullable', 'integer', 'min:1', 'max:6'],
            'cycle_settings.auto_carry_over' => ['nullable', 'boolean'],
            'auto_start' => ['nullable', 'boolean'],
        ]);

        $team->fill(array_filter(['estimate_scale' => $data['estimate_scale'] ?? null], fn ($v) => $v !== null));

        if (array_key_exists('cycle_settings', $data)) {
            $team->cycle_settings = array_merge($team->cycleSettings(), $data['cycle_settings'] ?? []);
        }

        $team->save();
        $this->audit->record('team.project_settings_updated', $currentOrganization, $request->user(), $team, $data);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['team' => ['cycle_settings' => $team->cycleSettings(), 'estimate_scale' => $team->estimate_scale]]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team settings saved')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function overview(Team $team, User $user): array
    {
        $open = IssueWorkstream::query()->where('team_id', $team->id)->whereNotIn('status_category', ['completed', 'canceled']);

        return [
            'counts' => [
                'open' => (clone $open)->count(),
                'started' => (clone $open)->where('status_category', 'started')->count(),
                'blocked' => (clone $open)->where('blocked_by_count', '>', 0)->count(),
                'unassigned' => (clone $open)->doesntHave('assignees')->count(),
                'completed_30d' => IssueWorkstream::query()->where('team_id', $team->id)->where('status_category', 'completed')->where('completed_at', '>=', now()->subDays(30))->count(),
            ],
            'by_status' => IssueWorkstream::query()->where('team_id', $team->id)->whereNotIn('status_category', ['completed', 'canceled'])
                ->selectRaw('status_id, COUNT(*) AS aggregate')->groupBy('status_id')->pluck('aggregate', 'status_id')->map(fn ($v) => (int) $v)->all(),
            'blocked' => IssueListItemResource::collection(
                Issue::query()->visibleTo($user)->with(IssueQuery::EAGER)
                    ->whereHas('workstreams', fn (Builder $w) => $w->where('team_id', $team->id)->where('blocked_by_count', '>', 0)->whereNotIn('status_category', ['completed', 'canceled']))
                    ->limit(8)->get()
            )->resolve(),
            'recent' => IssueListItemResource::collection(
                Issue::query()->visibleTo($user)->with(IssueQuery::EAGER)
                    ->whereHas('workstreams', fn (Builder $w) => $w->where('team_id', $team->id))
                    ->orderByDesc('last_activity_at')->limit(8)->get()
            )->resolve(),
        ];
    }

    /**
     * Open workstreams per member, so leads can see who is overloaded.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function workload(Team $team): array
    {
        $rows = DB::table('issue_workstream_assignees')
            ->join('issue_workstreams', 'issue_workstreams.id', '=', 'issue_workstream_assignees.workstream_id')
            ->where('issue_workstreams.team_id', $team->id)
            ->whereNotIn('issue_workstreams.status_category', ['completed', 'canceled'])
            ->selectRaw('issue_workstream_assignees.user_id, COUNT(*) AS open, SUM(COALESCE(issue_workstreams.estimate, 0)) AS points, SUM(issue_workstreams.status_category = "started") AS started')
            ->groupBy('issue_workstream_assignees.user_id')
            ->get()
            ->keyBy('user_id');

        return $team->members()->get()->map(fn ($member) => [
            ...UserSummaryResource::make($member)->resolve(),
            'role' => $member->getRelation('membership')->role->value,
            'open' => (int) ($rows[$member->id]->open ?? 0),
            'started' => (int) ($rows[$member->id]->started ?? 0),
            'points' => (float) ($rows[$member->id]->points ?? 0),
        ])->values()->all();
    }
}
