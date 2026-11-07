<?php

namespace App\Http\Controllers\Projects;

use App\Enums\Projects\IssueRelationType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\Concerns\BuildsIssueLists;
use App\Http\Requests\Projects\SaveProjectRequest;
use App\Http\Resources\Projects\IssueActivityResource;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Http\Resources\Projects\MilestoneResource;
use App\Http\Resources\Projects\ProjectResource;
use App\Http\Resources\Projects\ProjectSummaryResource;
use App\Http\Resources\Projects\WorkflowResource;
use App\Models\Organization;
use App\Models\Projects\IssueActivity;
use App\Models\Projects\IssueRelation;
use App\Models\Projects\Milestone;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;
use App\Services\Projects\IssueLookups;
use App\Services\Projects\IssueQuery;
use App\Services\Projects\ProjectActions;
use App\Services\Projects\ProjectProgress;
use App\Services\Projects\RecentlyViewed;
use App\Services\Projects\WorkflowActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    use BuildsIssueLists;

    public const TABS = ['overview', 'issues', 'board', 'timeline', 'milestones', 'activity', 'settings'];

    public function __construct(
        protected ProjectActions $projects,
        protected IssueLookups $lookups,
        protected IssueAccess $access,
        protected WorkflowActions $workflows,
        protected ProjectProgress $progress,
        protected RecentlyViewed $recent,
    ) {}

    public function index(Request $request, Organization $currentOrganization): Response
    {
        $this->authorize('viewAny', [Project::class, $currentOrganization]);
        $user = $request->user();

        $status = $request->query('status', 'active');
        $query = Project::query()->tap(fn ($q) => $this->access->applyProjectVisibility($q, $user, $currentOrganization->id))->with(['lead', 'teams', 'product']);

        match ($status) {
            'completed' => $query->whereIn('status', ['completed', 'canceled']),
            'all' => null,
            default => $query->whereNotIn('status', ['completed', 'canceled']),
        };

        if ($request->filled('product')) {
            $query->whereHas('product', fn ($q) => $q->where('slug', $request->query('product')));
        }

        return Inertia::render('projects/projects/Index', [
            'projects' => ProjectSummaryResource::collection($query->orderByRaw('target_date IS NULL')->orderBy('target_date')->orderBy('name')->get())->resolve(),
            'status' => $status,
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
            'can' => ['create' => $user->can('create', [Project::class, $currentOrganization])],
        ]);
    }

    public function show(Request $request, Organization $currentOrganization, Project $project, string $tab = 'overview'): Response
    {
        $this->ensureBelongs($currentOrganization, $project);
        $this->authorize('view', $project);
        $user = $request->user();
        $tab = in_array($tab, self::TABS, true) ? $tab : 'overview';

        if ($tab === 'settings') {
            $this->authorize('update', $project);
        }

        $this->recent->touch($user, $currentOrganization, $project);

        $display = $this->display($request, ['group_by' => 'status', 'mode' => $tab === 'board' ? 'board' : ($tab === 'timeline' ? 'timeline' : 'list')]);
        $filters = $this->filters($request, [['field' => 'project', 'op' => 'in', 'value' => [$project->id]]]);
        $workflow = $this->workflows->issueWorkflowFor($currentOrganization, $project->product);

        return Inertia::render('projects/projects/Show', [
            'project' => ProjectResource::make($project)->resolve(),
            'tab' => $tab,
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
            'filters' => $filters,
            'display' => $display,
            'workflow' => fn () => WorkflowResource::make($workflow)->resolve(),
            'overview' => $this->tabProp($tab, 'overview', fn () => $this->overview($project, $user)),
            'issues' => in_array($tab, ['issues', 'timeline'], true) ? $this->issuesProp($request, $user, $filters, $display, $tab === 'timeline' ? 200 : 50) : Inertia::optional(fn () => null),
            'board' => $this->tabProp($tab, 'board', fn () => $this->boardColumns($request, $user, $filters, $display, $workflow->statuses)),
            'milestones' => $this->tabProp($tab, ['milestones', 'timeline'], fn () => MilestoneResource::collection($project->milestones()->get())->resolve()),
            'dependencies' => $this->tabProp($tab, 'timeline', fn () => $this->dependencies($project)),
            'activity' => $this->tabProp($tab, 'activity', fn () => $this->activity($project)),
        ]);
    }

    public function store(SaveProjectRequest $request, Organization $currentOrganization): RedirectResponse|JsonResponse
    {
        $this->authorize('create', [Project::class, $currentOrganization]);

        $project = $this->projects->create($currentOrganization, $request->validated(), $request->user());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['project' => ProjectResource::make($project)->resolve()], 201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created', ['name' => $project->name])]);

        return to_route('projects.projects.show', ['current_organization' => $currentOrganization->slug, 'project' => $project->slug]);
    }

    public function update(SaveProjectRequest $request, Organization $currentOrganization, Project $project): RedirectResponse|JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $project);
        $this->authorize('update', $project);

        if ($request->has('visibility')) {
            $this->authorize('setVisibility', $project);
        }

        $project = $this->projects->update($project, $request->validated(), $request->user());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['project' => ProjectResource::make($project)->resolve()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project saved')]);

        return back();
    }

    public function members(Request $request, Organization $currentOrganization, Project $project): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $project);
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'members' => ['present', 'array'],
            'members.*.user_id' => ['required', 'integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $currentOrganization->id)],
            'members.*.role' => ['nullable', Rule::in(['lead', 'member'])],
        ]);

        $project = $this->projects->syncMembers($project, $data['members'], $request->user());

        return response()->json(['project' => ProjectResource::make($project)->resolve()]);
    }

    public function destroy(Request $request, Organization $currentOrganization, Project $project): RedirectResponse
    {
        $this->ensureBelongs($currentOrganization, $project);
        $this->authorize('delete', $project);

        $this->projects->delete($project, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project deleted')]);

        return to_route('projects.projects.index', ['current_organization' => $currentOrganization->slug]);
    }

    public function storeMilestone(Request $request, Organization $currentOrganization, Project $project): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $project);
        $this->authorize('update', $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:5000'],
            'target_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['planned', 'active', 'completed', 'missed'])],
        ]);

        $milestone = $this->projects->createMilestone($project, $data, $request->user());

        return response()->json(['milestone' => MilestoneResource::make($milestone)->resolve()], 201);
    }

    public function updateMilestone(Request $request, Organization $currentOrganization, Project $project, Milestone $milestone): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $project);
        abort_unless($milestone->project_id === $project->id, 404);
        $this->authorize('update', $project);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:5000'],
            'target_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['planned', 'active', 'completed', 'missed'])],
        ]);

        $milestone = $this->projects->updateMilestone($milestone, $data, $request->user());

        return response()->json(['milestone' => MilestoneResource::make($milestone)->resolve()]);
    }

    public function destroyMilestone(Request $request, Organization $currentOrganization, Project $project, Milestone $milestone): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $project);
        abort_unless($milestone->project_id === $project->id, 404);
        $this->authorize('update', $project);

        $this->projects->deleteMilestone($milestone, $request->user());

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function overview(Project $project, User $user): array
    {
        $progress = $this->progress->recompute($project);

        return [
            'progress' => $progress,
            'milestones' => MilestoneResource::collection($project->milestones()->get())->resolve(),
            'blockers' => IssueListItemResource::collection(
                $project->issues()->visibleTo($user)->with(IssueQuery::EAGER)->whereNotIn('status_category', ['completed', 'canceled'])->where(fn ($q) => $q->where('blocked_by_count', '>', 0)->orWhere('blocked_workstreams_count', '>', 0))->limit(10)->get()
            )->resolve(),
            'recent_issues' => IssueListItemResource::collection(
                $project->issues()->visibleTo($user)->with(IssueQuery::EAGER)->orderByDesc('last_activity_at')->limit(8)->get()
            )->resolve(),
            'recent_activity' => $this->activity($project, 15),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function activity(Project $project, int $limit = 60): array
    {
        return IssueActivity::query()
            ->whereIn('issue_id', $project->issues()->select('id'))
            ->with(['actor', 'issue:id,uuid,identifier,title', 'workstream.team'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($a) => [
                ...IssueActivityResource::make($a)->resolve(),
                'issue' => ['uuid' => $a->issue->uuid, 'identifier' => $a->issue->identifier, 'title' => $a->issue->title],
            ])->values()->all();
    }

    protected function ensureBelongs(Organization $organization, Project $project): void
    {
        abort_unless($project->organization_id === $organization->id, 404);
    }

    /**
     * Blocking relations between issues of the project, for the timeline's dependency arrows.
     *
     * @return array<int, array{from: string, to: string}>
     */
    protected function dependencies(Project $project): array
    {
        return IssueRelation::query()
            ->where('type', IssueRelationType::Blocks->value)
            ->whereIn('issue_id', fn ($q) => $q->select('id')->from('issues')->where('project_id', $project->id)->whereNull('deleted_at'))
            ->whereIn('related_issue_id', fn ($q) => $q->select('id')->from('issues')->where('project_id', $project->id)->whereNull('deleted_at'))
            ->join('issues as blocker', 'blocker.id', '=', 'issue_relations.issue_id')
            ->join('issues as blocked', 'blocked.id', '=', 'issue_relations.related_issue_id')
            ->get(['blocker.uuid as from_uuid', 'blocked.uuid as to_uuid'])
            ->map(fn ($row) => ['from' => $row->from_uuid, 'to' => $row->to_uuid])
            ->all();
    }
}
