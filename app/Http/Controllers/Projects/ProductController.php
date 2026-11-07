<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\Concerns\BuildsIssueLists;
use App\Http\Requests\Projects\SaveProductRequest;
use App\Http\Resources\Projects\IssueActivityResource;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Http\Resources\Projects\ProductResource;
use App\Http\Resources\Projects\ProductSummaryResource;
use App\Http\Resources\Projects\ProjectSummaryResource;
use App\Http\Resources\Projects\TeamSummaryResource;
use App\Http\Resources\Projects\WorkflowResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Organization;
use App\Models\Projects\IssueActivity;
use App\Models\Projects\Product;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;
use App\Services\Projects\IssueLookups;
use App\Services\Projects\IssueQuery;
use App\Services\Projects\ProductActions;
use App\Services\Projects\ProjectProgress;
use App\Services\Projects\RecentlyViewed;
use App\Services\Projects\WorkflowActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use BuildsIssueLists;

    public const TABS = ['overview', 'issues', 'modules', 'projects', 'roadmap', 'releases', 'activity', 'settings'];

    public function __construct(
        protected ProductActions $products,
        protected IssueLookups $lookups,
        protected IssueAccess $access,
        protected WorkflowActions $workflows,
        protected ProjectProgress $progress,
        protected RecentlyViewed $recent,
    ) {}

    public function index(Request $request, Organization $currentOrganization): Response
    {
        $this->authorize('viewAny', [Product::class, $currentOrganization]);
        $user = $request->user();

        $products = Product::query()->where('organization_id', $currentOrganization->id)->with(['owner', 'teams', 'modules'])->orderBy('name')->get()
            ->filter(fn (Product $p) => $this->access->productLevelFor($user, $p) !== null)
            ->values();

        return Inertia::render('projects/products/Index', [
            'products' => $products->map(fn (Product $p) => [
                ...ProductSummaryResource::make($p)->resolve(),
                'description' => $p->description,
                'owner' => $p->owner ? UserSummaryResource::make($p->owner)->resolve() : null,
                'teams' => TeamSummaryResource::collection($p->teams)->resolve(),
                'modules_count' => $p->modules->count(),
            ])->all(),
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
            'can' => ['create' => $user->can('create', [Product::class, $currentOrganization])],
        ]);
    }

    public function show(Request $request, Organization $currentOrganization, Product $product, string $tab = 'overview'): Response
    {
        $this->ensureBelongs($currentOrganization, $product);
        $this->authorize('view', $product);
        $user = $request->user();
        $tab = in_array($tab, self::TABS, true) ? $tab : 'overview';

        if ($tab === 'settings') {
            $this->authorize('update', $product);
        }

        $this->recent->touch($user, $currentOrganization, $product);

        $display = $this->display($request, ['group_by' => 'status']);
        $filters = $this->filters($request, [['field' => 'product', 'op' => 'in', 'value' => [$product->id]]]);
        $workflow = $this->workflows->issueWorkflowFor($currentOrganization, $product);

        return Inertia::render('projects/products/Show', [
            'product' => ProductResource::make($product)->resolve(),
            'tab' => $tab,
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
            'filters' => $filters,
            'display' => $display,
            'workflow' => fn () => WorkflowResource::make($workflow)->resolve(),
            'overview' => $this->tabProp($tab, 'overview', fn () => $this->overview($product, $user)),
            'issues' => $tab === 'issues' ? $this->issuesProp($request, $user, $filters, $display) : Inertia::optional(fn () => null),
            'board' => $this->tabProp($display['mode'], 'board', fn () => $this->boardColumns($request, $user, $filters, $display, $workflow->statuses)),
            'projects' => $this->tabProp($tab, ['projects', 'roadmap'], fn () => ProjectSummaryResource::collection(
                $product->projects()->tap(fn ($q) => $this->access->applyProjectVisibility($q, $user, $currentOrganization->id))->with(['lead', 'teams', 'product'])->orderBy('target_date')->get()
            )->resolve()),
            'releases' => $this->tabProp($tab, ['releases', 'roadmap'], fn () => $product->releases()->get()->map(fn ($release) => [
                'id' => $release->id, 'uuid' => $release->uuid, 'name' => $release->name, 'version' => $release->version, 'status' => $release->status->value,
                'planned_at' => $release->planned_at?->toDateString(), 'released_at' => $release->released_at?->toISOString(),
                'issues_count' => $release->issues_count, 'completed_issues_count' => $release->completed_issues_count,
            ])->values()->all()),
            'activity' => $this->tabProp($tab, 'activity', fn () => $this->activity($product)),
        ]);
    }

    public function store(SaveProductRequest $request, Organization $currentOrganization): RedirectResponse|JsonResponse
    {
        $this->authorize('create', [Product::class, $currentOrganization]);

        $product = $this->products->create($currentOrganization, $request->validated(), $request->user());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['product' => ProductResource::make($product)->resolve()], 201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created', ['name' => $product->name])]);

        return to_route('projects.products.show', ['current_organization' => $currentOrganization->slug, 'product' => $product->slug]);
    }

    public function update(SaveProductRequest $request, Organization $currentOrganization, Product $product): RedirectResponse|JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $product);
        $this->authorize('update', $product);

        $product = $this->products->update($product, $request->validated(), $request->user());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['product' => ProductResource::make($product)->resolve()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product saved')]);

        return back();
    }

    public function members(Request $request, Organization $currentOrganization, Product $product): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $product);
        $this->authorize('manageMembers', $product);

        $data = $request->validate([
            'members' => ['present', 'array'],
            'members.*.user_id' => ['required', 'integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $currentOrganization->id)],
            'members.*.role' => ['nullable', Rule::in(['admin', 'member'])],
        ]);

        $product = $this->products->syncMembers($product, $data['members'], $request->user());

        return response()->json(['product' => ProductResource::make($product)->resolve()]);
    }

    public function workflow(Request $request, Organization $currentOrganization, Product $product): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $product);
        $this->authorize('manageWorkflow', $product);

        $workflow = $product->issue_workflow_id ? $product->issueWorkflow : $this->workflows->createProductWorkflow($product, $request->user());

        return response()->json(['workflow' => WorkflowResource::make($workflow)->resolve()]);
    }

    public function destroy(Request $request, Organization $currentOrganization, Product $product): RedirectResponse
    {
        $this->ensureBelongs($currentOrganization, $product);
        $this->authorize('delete', $product);

        $this->products->delete($product, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product deleted')]);

        return to_route('projects.products.index', ['current_organization' => $currentOrganization->slug]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function overview(Product $product, User $user): array
    {
        $open = $product->issues()->visibleTo($user)->whereNotIn('status_category', ['completed', 'canceled']);

        return [
            'counts' => [
                'open' => (clone $open)->count(),
                'started' => (clone $open)->where('status_category', 'started')->count(),
                'blocked' => (clone $open)->where(fn ($q) => $q->where('blocked_by_count', '>', 0)->orWhere('blocked_workstreams_count', '>', 0))->count(),
                'completed_30d' => $product->issues()->visibleTo($user)->where('status_category', 'completed')->where('completed_at', '>=', now()->subDays(30))->count(),
            ],
            'by_module' => $product->modules()->withCount(['issues as open_count' => fn ($q) => $q->whereNotIn('status_category', ['completed', 'canceled'])])->orderBy('position')->get()
                ->map(fn ($m) => ['id' => $m->id, 'slug' => $m->slug, 'name' => $m->name, 'color' => $m->color, 'open' => (int) $m->getAttribute('open_count'), 'parent_id' => $m->parent_id])->values()->all(),
            'projects' => $this->progress->forProduct($product),
            'active_projects' => ProjectSummaryResource::collection(
                $product->projects()->tap(fn ($q) => $this->access->applyProjectVisibility($q, $user, $product->organization_id))->whereIn('status', ['planned', 'in_progress'])->with(['lead', 'teams', 'product'])->orderBy('target_date')->limit(6)->get()
            )->resolve(),
            'recent_issues' => IssueListItemResource::collection(
                $product->issues()->visibleTo($user)->with(IssueQuery::EAGER)->orderByDesc('last_activity_at')->limit(8)->get()
            )->resolve(),
            'upcoming_releases' => $product->releases()->whereIn('status', ['planned', 'in_progress'])->orderBy('planned_at')->limit(4)->get()
                ->map(fn ($r) => ['uuid' => $r->uuid, 'name' => $r->name, 'version' => $r->version, 'planned_at' => $r->planned_at?->toDateString(), 'status' => $r->status->value, 'issues_count' => $r->issues_count, 'completed_issues_count' => $r->completed_issues_count])->values()->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function activity(Product $product): array
    {
        return IssueActivity::query()
            ->whereIn('issue_id', $product->issues()->select('id'))
            ->with(['actor', 'issue:id,uuid,identifier,title', 'workstream.team'])
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(fn ($a) => [
                ...IssueActivityResource::make($a)->resolve(),
                'issue' => ['uuid' => $a->issue->uuid, 'identifier' => $a->issue->identifier, 'title' => $a->issue->title],
            ])->values()->all();
    }

    protected function ensureBelongs(Organization $organization, Product $product): void
    {
        abort_unless($product->organization_id === $organization->id, 404);
    }
}
