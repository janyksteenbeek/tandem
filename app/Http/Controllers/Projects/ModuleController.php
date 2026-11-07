<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\Concerns\BuildsIssueLists;
use App\Http\Requests\Projects\SaveModuleRequest;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Http\Resources\Projects\ModuleResource;
use App\Http\Resources\Projects\ProductResource;
use App\Http\Resources\Projects\ProjectSummaryResource;
use App\Http\Resources\Projects\WorkflowStatusResource;
use App\Models\Organization;
use App\Models\Projects\Module;
use App\Models\Projects\Product;
use App\Services\Projects\Access\IssueAccess;
use App\Services\Projects\IssueLookups;
use App\Services\Projects\IssueQuery;
use App\Services\Projects\ProductActions;
use App\Services\Projects\RecentlyViewed;
use App\Services\Projects\WorkflowActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ModuleController extends Controller
{
    use BuildsIssueLists;

    public function __construct(protected ProductActions $products, protected IssueLookups $lookups, protected IssueAccess $access, protected WorkflowActions $workflows, protected RecentlyViewed $recent) {}

    public function show(Request $request, Organization $currentOrganization, Product $product, Module $module): Response
    {
        abort_unless($product->organization_id === $currentOrganization->id && $module->product_id === $product->id, 404);
        $this->authorize('view', $module);
        $user = $request->user();
        $this->recent->touch($user, $currentOrganization, $module);

        $moduleIds = $this->descendantIds($module);
        $display = $this->display($request, ['group_by' => 'status']);
        $filters = $this->filters($request, [['field' => 'module', 'op' => 'in', 'value' => $moduleIds]]);
        $workflow = $this->workflows->issueWorkflowFor($currentOrganization, $product);

        return Inertia::render('projects/products/Module', [
            'product' => ProductResource::make($product)->resolve(),
            'module' => ModuleResource::make($module->load(['owner', 'teams', 'children']))->resolve(),
            'breadcrumbs' => $this->ancestors($module),
            'filters' => $filters,
            'display' => $display,
            'workflow' => fn () => WorkflowStatusResource::collection($workflow->statuses)->resolve(),
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
            'issues' => $this->issuesProp($request, $user, $filters, $display),
            'board' => $this->tabProp($display['mode'], 'board', fn () => $this->boardColumns($request, $user, $filters, $display, $workflow->statuses)),
            'overview' => fn () => [
                'open' => $module->issues()->visibleTo($user)->whereNotIn('status_category', ['completed', 'canceled'])->count(),
                'blocked' => $module->issues()->visibleTo($user)->whereNotIn('status_category', ['completed', 'canceled'])->where(fn ($q) => $q->where('blocked_by_count', '>', 0)->orWhere('blocked_workstreams_count', '>', 0))->count(),
                'completed_30d' => $module->issues()->visibleTo($user)->where('status_category', 'completed')->where('completed_at', '>=', now()->subDays(30))->count(),
                'projects' => ProjectSummaryResource::collection($module->projects()->tap(fn ($q) => $this->access->applyProjectVisibility($q, $user, $currentOrganization->id))->with(['lead', 'teams', 'product'])->get())->resolve(),
                'blockers' => IssueListItemResource::collection($module->issues()->visibleTo($user)->with(IssueQuery::EAGER)->where(fn ($q) => $q->where('blocked_by_count', '>', 0)->orWhere('blocked_workstreams_count', '>', 0))->limit(10)->get())->resolve(),
                'recently_completed' => IssueListItemResource::collection($module->issues()->visibleTo($user)->with(IssueQuery::EAGER)->where('status_category', 'completed')->orderByDesc('completed_at')->limit(8)->get())->resolve(),
            ],
        ]);
    }

    public function store(SaveModuleRequest $request, Organization $currentOrganization, Product $product): JsonResponse
    {
        abort_unless($product->organization_id === $currentOrganization->id, 404);
        $this->authorize('update', $product);

        $module = $this->products->createModule($product, $request->validated(), $request->user());

        return response()->json(['module' => ModuleResource::make($module->load(['owner', 'teams']))->resolve()], 201);
    }

    public function update(SaveModuleRequest $request, Organization $currentOrganization, Product $product, Module $module): JsonResponse
    {
        abort_unless($product->organization_id === $currentOrganization->id && $module->product_id === $product->id, 404);
        $this->authorize('update', $module);

        $module = $this->products->updateModule($module, $request->validated(), $request->user());

        return response()->json(['module' => ModuleResource::make($module->load(['owner', 'teams']))->resolve()]);
    }

    public function reorder(Request $request, Organization $currentOrganization, Product $product): JsonResponse
    {
        abort_unless($product->organization_id === $currentOrganization->id, 404);
        $this->authorize('update', $product);

        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        $this->products->reorderModules($product, array_map('intval', $data['ids']), $request->user());

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Organization $currentOrganization, Product $product, Module $module): JsonResponse
    {
        abort_unless($product->organization_id === $currentOrganization->id && $module->product_id === $product->id, 404);
        $this->authorize('delete', $module);

        $this->products->deleteModule($module, $request->user());

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<int, int>
     */
    protected function descendantIds(Module $module): array
    {
        $ids = [$module->id];
        $frontier = [$module->id];

        while ($frontier !== []) {
            $frontier = Module::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /**
     * @return array<int, array{slug: string, name: string}>
     */
    protected function ancestors(Module $module): array
    {
        $chain = [];
        $cursor = $module->parent;

        while ($cursor) {
            array_unshift($chain, ['slug' => $cursor->slug, 'name' => $cursor->name]);
            $cursor = $cursor->parent;
        }

        return $chain;
    }
}
