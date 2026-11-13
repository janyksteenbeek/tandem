<?php

namespace App\Http\Controllers\Projects;

use App\Enums\Projects\IssueViewScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\Concerns\BuildsIssueLists;
use App\Http\Requests\Projects\SaveIssueViewRequest;
use App\Http\Resources\Projects\WorkflowStatusResource;
use App\Models\Organization;
use App\Models\Projects\IssueView;
use App\Services\Projects\IssueFilterCatalog;
use App\Services\Projects\IssueLookups;
use App\Services\Projects\RecentlyViewed;
use App\Services\Projects\WorkflowActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IssueViewController extends Controller
{
    use BuildsIssueLists;

    public function __construct(protected IssueLookups $lookups, protected IssueFilterCatalog $catalog, protected WorkflowActions $workflows, protected RecentlyViewed $recent) {}

    public function show(Request $request, Organization $currentOrganization, IssueView $view): Response
    {
        $this->authorize('view', $view);
        $user = $request->user();
        $this->recent->touch($user, $currentOrganization, $view);

        $display = $this->display($request, array_merge(['group_by' => 'status'], $view->display ?? []));
        $filters = $request->has('filters') || $request->has('q') ? $this->filters($request) : $this->catalog->normalize($view->filters);
        $workflow = $this->workflows->defaultIssueWorkflow($currentOrganization);

        return Inertia::render('projects/issues/Index', [
            'container' => ['type' => 'view', 'id' => $view->id, 'name' => $view->name, 'url' => "/{$currentOrganization->slug}/views/{$view->uuid}"],
            'view' => ['id' => $view->id, 'uuid' => $view->uuid, 'name' => $view->name, 'scope' => $view->scope->value, 'icon' => $view->icon, 'color' => $view->color, 'can_manage' => $user->can('manage', $view)],
            'filters' => $filters,
            'display' => $display,
            'issues' => $this->issuesProp($request, $user, $filters, $display),
            'board' => $this->tabProp($display['mode'], 'board', fn () => $this->boardColumns($request, $user, $filters, $display, $workflow->statuses)),
            'workflow' => fn () => WorkflowStatusResource::collection($workflow->statuses)->resolve(),
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
        ]);
    }

    public function store(SaveIssueViewRequest $request, Organization $currentOrganization): JsonResponse
    {
        $data = $request->validated();
        $scope = IssueViewScope::tryFrom($data['scope'] ?? 'personal') ?? IssueViewScope::Personal;

        if ($scope === IssueViewScope::Organization) {
            $this->authorize('manage', $currentOrganization);
        }

        $view = IssueView::query()->create([
            'organization_id' => $currentOrganization->id,
            'scope' => $scope,
            'user_id' => $scope === IssueViewScope::Personal ? $request->user()->id : null,
            'team_id' => $scope === IssueViewScope::Team ? $data['team_id'] : null,
            'project_id' => $data['project_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? null,
            'filters' => $this->catalog->normalize($data['filters'] ?? null),
            'display' => $data['display'] ?? null,
            'created_by_id' => $request->user()->id,
            'position' => (int) IssueView::query()->where('organization_id', $currentOrganization->id)->max('position') + 1,
        ]);

        if ($scope === IssueViewScope::Team) {
            $this->authorize('view', $view);
        }

        return response()->json(['view' => $this->summary($view)], 201);
    }

    public function update(SaveIssueViewRequest $request, Organization $currentOrganization, IssueView $view): JsonResponse
    {
        $this->authorize('manage', $view);
        $data = $request->validated();

        $view->fill(array_intersect_key($data, array_flip(['name', 'icon', 'color', 'project_id', 'product_id'])));

        if (array_key_exists('filters', $data)) {
            $view->filters = $this->catalog->normalize($data['filters']);
        }

        if (array_key_exists('display', $data)) {
            $view->display = $data['display'];
        }

        $view->save();

        return response()->json(['view' => $this->summary($view)]);
    }

    public function destroy(Request $request, Organization $currentOrganization, IssueView $view): JsonResponse
    {
        $this->authorize('manage', $view);
        $view->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(IssueView $view): array
    {
        return [
            'id' => $view->id,
            'uuid' => $view->uuid,
            'name' => $view->name,
            'icon' => $view->icon,
            'color' => $view->color,
            'scope' => $view->scope->value,
            'team_id' => $view->team_id,
            'filters' => $view->filters,
            'display' => $view->display,
        ];
    }
}
