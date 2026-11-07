<?php

namespace App\Http\Controllers\Projects;

use App\Enums\Projects\IssueLinkKind;
use App\Enums\Projects\IssuePriority;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\Concerns\BuildsIssueLists;
use App\Http\Requests\Projects\SaveIssueRequest;
use App\Http\Resources\Projects\IssueActivityResource;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Http\Resources\Projects\IssueResource;
use App\Http\Resources\Projects\WorkflowStatusResource;
use App\Models\Mail\Conversation;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueLabel;
use App\Models\Projects\Milestone;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Projects\WorkflowStatus;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\ConversationIssueLinks;
use App\Services\Projects\IssueActions;
use App\Services\Projects\IssueLookups;
use App\Services\Projects\IssueQuery;
use App\Services\Projects\RecentlyViewed;
use App\Services\Projects\WorkflowActions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IssueController extends Controller
{
    use BuildsIssueLists;

    public function __construct(
        protected IssueActions $actions,
        protected IssueLookups $lookups,
        protected WorkflowActions $workflows,
        protected RecentlyViewed $recent,
    ) {}

    /**
     * Every issue the user can see in the organization.
     */
    public function index(Request $request, Organization $currentOrganization): Response
    {
        $user = $request->user();
        $this->authorize('viewAny', [Issue::class, $currentOrganization]);

        $display = $this->display($request);
        $filters = $this->filters($request);
        $workflow = $this->workflows->defaultIssueWorkflow($currentOrganization);

        return Inertia::render('projects/issues/Index', [
            'container' => ['type' => 'organization', 'id' => $currentOrganization->id, 'name' => $currentOrganization->name, 'url' => "/{$currentOrganization->slug}/issues"],
            'filters' => $filters,
            'display' => $display,
            'issues' => $this->issuesProp($request, $user, $filters, $display),
            'board' => Inertia::optional(fn () => $this->boardColumns($request, $user, $filters, $display, $workflow->statuses)),
            'workflow' => fn () => WorkflowStatusResource::collection($workflow->statuses)->resolve(),
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
            'open_create' => $request->boolean('create'),
        ]);
    }

    public function show(Request $request, Organization $currentOrganization, Issue $issue): Response
    {
        $this->authorize('view', $issue);
        $user = $request->user();

        $this->recent->touch($user, $currentOrganization, $issue);

        return Inertia::render('projects/issues/Show', [
            'issue' => IssueResource::make($issue)->resolve(),
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
            'activity' => Inertia::defer(fn () => IssueActivityResource::collection($issue->activities()->with(['actor', 'workstream.team'])->get())->resolve()),
            'linkedConversations' => Inertia::defer(fn () => app(ConversationIssueLinks::class)->visibleLinksFor($issue, $user)),
            'from' => $request->query('from'),
        ]);
    }

    public function store(SaveIssueRequest $request, Organization $currentOrganization): JsonResponse
    {
        $this->authorize('create', [Issue::class, $currentOrganization]);
        $user = $request->user();
        $data = $request->validated();

        $product = isset($data['product_id']) ? Product::query()->where('organization_id', $currentOrganization->id)->whereKey($data['product_id'])->first() : null;
        $project = isset($data['project_id']) ? Project::query()->where('organization_id', $currentOrganization->id)->whereKey($data['project_id'])->first() : null;

        if ($product) {
            $this->authorize('view', $product);
        }

        if ($project) {
            $this->authorize('contribute', $project);
        }

        $parent = isset($data['parent_id']) ? Issue::query()->where('organization_id', $currentOrganization->id)->whereKey($data['parent_id'])->first() : null;

        if ($parent) {
            $this->authorize('view', $parent);
        }

        $status = isset($data['status_id']) ? WorkflowStatus::query()->whereKey($data['status_id'])->first() : null;

        /** @var array<int, array<string, mixed>> $teamsInput */
        $teamsInput = $data['teams'] ?? [];

        $teams = collect($teamsInput)->map(fn (array $entry) => [
            'team' => Team::query()->where('organization_id', $currentOrganization->id)->whereKey($entry['team_id'])->firstOrFail(),
            'responsibility' => $entry['responsibility'] ?? null,
            'is_required' => $entry['is_required'] ?? true,
            'assignees' => $entry['assignee_ids'] ?? [],
        ])->all();

        $issue = $this->actions->create($currentOrganization, [
            'title' => $data['title'],
            'description_html' => $data['description_html'] ?? null,
            'product' => $product,
            'project' => $project,
            'milestone' => isset($data['milestone_id']) ? Milestone::query()->whereKey($data['milestone_id'])->first() : null,
            'parent' => $parent,
            'priority' => IssuePriority::tryFromKey($data['priority'] ?? null),
            'owner' => isset($data['owner_id']) ? User::query()->whereKey($data['owner_id'])->first() : null,
            'lead_team' => isset($data['lead_team_id']) ? Team::query()->whereKey($data['lead_team_id'])->first() : null,
            'status' => $status,
            'labels' => $data['label_ids'] ?? [],
            'modules' => $data['module_ids'] ?? [],
            'estimate' => $data['estimate'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ], $user, $teams);

        if (! empty($data['conversation_uuid'])) {
            $conversation = Conversation::query()->where('uuid', $data['conversation_uuid'])->first();

            if ($conversation && $user->can('view', $conversation)) {
                app(ConversationIssueLinks::class)->link($issue, $conversation, $user, null, IssueLinkKind::CreatedFrom);
            }
        }

        return $this->respond($issue, 201);
    }

    public function update(SaveIssueRequest $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('update', $issue);

        $data = $request->validated();

        $this->actions->update($issue, array_intersect_key($data, array_flip(['title', 'description_html', 'estimate', 'start_date', 'due_date'])) + [
            'labels' => $data['label_ids'] ?? null,
            'modules' => $data['module_ids'] ?? null,
        ], $request->user());

        return $this->respond($issue);
    }

    public function status(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('setStatus', $issue);

        $data = $request->validate([
            'status_id' => ['required', 'integer', Rule::exists('workflow_statuses', 'id')],
            'override' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $override = (bool) ($data['override'] ?? false);

        if ($override) {
            $this->authorize('overrideCompletion', $issue);
        }

        $this->actions->setStatus($issue, WorkflowStatus::query()->whereKey($data['status_id'])->firstOrFail(), $request->user(), 'manual', $override, $data['reason'] ?? null);

        return $this->respond($issue);
    }

    public function priority(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('update', $issue);

        $data = $request->validate(['priority' => ['required', Rule::in(array_map(fn (IssuePriority $p) => $p->key(), IssuePriority::cases()))]]);
        $this->actions->setPriority($issue, IssuePriority::fromKey($data['priority']), $request->user());

        return $this->respond($issue);
    }

    public function owner(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('update', $issue);

        $data = $request->validate(['owner_id' => ['nullable', 'integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $currentOrganization->id)]]);
        $this->actions->setOwner($issue, isset($data['owner_id']) ? User::query()->whereKey($data['owner_id'])->first() : null, $request->user());

        return $this->respond($issue);
    }

    public function leadTeam(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('update', $issue);

        $data = $request->validate(['team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')->where('organization_id', $currentOrganization->id)]]);
        $this->actions->setLeadTeam($issue, isset($data['team_id']) ? Team::query()->whereKey($data['team_id'])->first() : null, $request->user());

        return $this->respond($issue);
    }

    public function parent(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('update', $issue);

        $data = $request->validate(['parent_identifier' => ['nullable', 'string', 'max:24']]);
        $parent = filled($data['parent_identifier'] ?? null)
            ? Issue::query()->where('organization_id', $currentOrganization->id)->where('identifier', strtoupper($data['parent_identifier']))->firstOrFail()
            : null;

        if ($parent) {
            $this->authorize('view', $parent);
        }

        $this->actions->setParent($issue, $parent, $request->user());

        return $this->respond($issue);
    }

    public function milestone(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('update', $issue);

        $data = $request->validate(['milestone_id' => ['nullable', 'integer', Rule::exists('milestones', 'id')->where('organization_id', $currentOrganization->id)]]);
        $this->actions->setMilestone($issue, isset($data['milestone_id']) ? Milestone::query()->whereKey($data['milestone_id'])->first() : null, $request->user());

        return $this->respond($issue);
    }

    public function labels(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('update', $issue);

        $data = $request->validate([
            'label_id' => ['nullable', 'integer', Rule::exists('issue_labels', 'id')->where('organization_id', $currentOrganization->id)],
            'name' => ['nullable', 'string', 'max:60'],
            'attached' => ['required', 'boolean'],
        ]);

        $label = isset($data['label_id'])
            ? IssueLabel::query()->whereKey($data['label_id'])->firstOrFail()
            : IssueLabel::query()->firstOrCreate(
                ['organization_id' => $currentOrganization->id, 'scope_key' => 'org', 'slug' => Str::slug((string) ($data['name'] ?? ''))],
                ['name' => (string) ($data['name'] ?? ''), 'color' => 'slate'],
            );

        $this->actions->toggleLabel($issue, $label, (bool) $data['attached'], $request->user());

        return $this->respond($issue);
    }

    public function modules(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('update', $issue);

        $data = $request->validate(['module_ids' => ['present', 'array'], 'module_ids.*' => ['integer']]);
        $this->actions->syncModules($issue, array_map('intval', $data['module_ids']), $request->user());

        return $this->respond($issue);
    }

    public function move(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('move', $issue);

        $data = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('organization_id', $currentOrganization->id)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('organization_id', $currentOrganization->id)],
        ]);

        $product = isset($data['product_id']) ? Product::query()->whereKey($data['product_id'])->first() : null;
        $project = isset($data['project_id']) ? Project::query()->whereKey($data['project_id'])->first() : null;

        if ($project) {
            $this->authorize('contribute', $project);
        }

        if ($product) {
            $this->authorize('view', $product);
        }

        $this->actions->move($issue, $product, $project, $request->user());

        return $this->respond($issue);
    }

    public function follow(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('follow', $issue);

        $data = $request->validate(['following' => ['required', 'boolean']]);
        $this->actions->follow($issue, $request->user(), (bool) $data['following']);

        return $this->respond($issue);
    }

    public function destroy(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('delete', $issue);
        $this->actions->delete($issue, $request->user());

        return response()->json(['deleted' => true]);
    }

    public function restore(Request $request, Organization $currentOrganization, string $identifier): JsonResponse
    {
        $model = Issue::onlyTrashed()->where('organization_id', $currentOrganization->id)->where('identifier', strtoupper($identifier))->firstOrFail();
        $this->authorize('restore', $model);
        $this->actions->restore($model, $request->user());

        return $this->respond($model);
    }

    /**
     * Apply one change to many issues. Issues the user may not change are skipped silently.
     */
    public function bulk(Request $request, Organization $currentOrganization): JsonResponse
    {
        $data = $request->validate([
            'issues' => ['required', 'array', 'max:200'],
            'issues.*' => ['string'],
            'action' => ['required', Rule::in(['status', 'priority', 'owner', 'label', 'project', 'delete'])],
            'status_id' => ['nullable', 'integer'],
            'priority' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer'],
            'label_id' => ['nullable', 'integer'],
            'attached' => ['nullable', 'boolean'],
            'project_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $issues = Issue::query()->where('organization_id', $currentOrganization->id)->whereIn('uuid', $data['issues'])->get();
        $updated = [];

        foreach ($issues as $issue) {
            try {
                $ok = match ($data['action']) {
                    'status' => $user->can('setStatus', $issue) && $this->actions->setStatus($issue, WorkflowStatus::query()->whereKey($data['status_id'])->firstOrFail(), $user),
                    'priority' => $user->can('update', $issue) && $this->actions->setPriority($issue, IssuePriority::fromKey((string) $data['priority']), $user),
                    'owner' => $user->can('update', $issue) && $this->actions->setOwner($issue, isset($data['owner_id']) ? User::query()->whereKey($data['owner_id'])->first() : null, $user),
                    'label' => $user->can('update', $issue) && $this->actions->toggleLabel($issue, IssueLabel::query()->whereKey($data['label_id'])->firstOrFail(), (bool) ($data['attached'] ?? true), $user),
                    'project' => $user->can('move', $issue) && $this->actions->move($issue, $issue->product, isset($data['project_id']) ? Project::query()->whereKey($data['project_id'])->first() : null, $user),
                    'delete' => $user->can('delete', $issue) && $this->deleteForBulk($issue, $user),
                    default => false,
                };
            } catch (\Throwable) {
                $ok = false;
            }

            if ($ok) {
                $updated[] = $issue;
            }
        }

        return response()->json([
            'issues' => $data['action'] === 'delete'
                ? collect($updated)->map(fn (Issue $i) => ['uuid' => $i->uuid, 'deleted' => true])->all()
                : IssueListItemResource::collection(collect($updated)->map(fn (Issue $i) => $i->fresh(IssueQuery::EAGER)))->resolve(),
        ]);
    }

    /**
     * Quick search for pickers and the command palette.
     */
    public function search(Request $request, Organization $currentOrganization): JsonResponse
    {
        $user = $request->user();
        $term = (string) $request->query('q', '');
        $limit = min(max((int) $request->query('limit', 10), 1), 25);

        $issues = Issue::query()->visibleTo($user)->with(['status', 'workstreams.assignees', 'project', 'product'])
            ->when($term !== '', fn (Builder $q) => $q->search($term))
            ->when($request->filled('exclude'), fn (Builder $q) => $q->where('identifier', '!=', strtoupper((string) $request->query('exclude'))))
            ->orderByDesc('last_activity_at')
            ->limit($limit)
            ->get();

        return response()->json(['issues' => IssueListItemResource::collection($issues)->resolve()]);
    }

    public function lookups(Request $request, Organization $currentOrganization): JsonResponse
    {
        return response()->json(['lookups' => $this->lookups->for($request->user(), $currentOrganization)]);
    }

    protected function deleteForBulk(Issue $issue, User $user): bool
    {
        $this->actions->delete($issue, $user);

        return true;
    }

    protected function respond(Issue $issue, int $status = 200): JsonResponse
    {
        return response()->json(['issue' => IssueResource::make($issue->fresh(IssueQuery::EAGER))->resolve()], $status);
    }
}
