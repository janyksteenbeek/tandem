<?php

namespace App\Services\Projects;

use App\Enums\Projects\CycleStatus;
use App\Enums\Projects\IssuePriority;
use App\Enums\Projects\StatusCategory;
use App\Http\Resources\Projects\CycleResource;
use App\Http\Resources\Projects\IssueLabelResource;
use App\Http\Resources\Projects\MilestoneResource;
use App\Http\Resources\Projects\ModuleResource;
use App\Http\Resources\Projects\ProductSummaryResource;
use App\Http\Resources\Projects\ProjectSummaryResource;
use App\Http\Resources\Projects\TeamSummaryResource;
use App\Http\Resources\Projects\WorkflowStatusResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Organization;
use App\Models\Projects\Cycle;
use App\Models\Projects\IssueLabel;
use App\Models\Projects\Milestone;
use App\Models\Projects\Module;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Projects\Workflow;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;

/**
 * Everything the pickers, filter bar and create dialog need: members, teams, products with
 * modules, projects, milestones, cycles, labels and every workflow's statuses.
 */
class IssueLookups
{
    public function __construct(protected IssueAccess $access, protected WorkflowActions $workflows) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, Organization $organization): array
    {
        $members = $organization->members()->orderBy('name')->get();
        $teams = $organization->teams()->get();
        $products = Product::query()->where('organization_id', $organization->id)->active()->orderBy('name')->get()
            ->filter(fn (Product $p) => $this->access->productLevelFor($user, $p) !== null)->values();
        $projects = Project::query()->tap(fn ($q) => $this->access->applyProjectVisibility($q, $user, $organization->id))->with(['lead', 'product'])->orderBy('name')->get();
        $modules = Module::query()->whereIn('product_id', $products->pluck('id'))->orderBy('position')->orderBy('name')->get();
        $milestones = Milestone::query()->whereIn('project_id', $projects->pluck('id'))->orderBy('position')->get();
        $cycles = Cycle::query()->whereIn('team_id', $teams->pluck('id'))->whereIn('status', [CycleStatus::Active->value, CycleStatus::Upcoming->value])->orderBy('starts_at')->get();
        $labels = IssueLabel::query()->where('organization_id', $organization->id)->orderBy('name')->get();

        $default = $this->workflows->defaultIssueWorkflow($organization);
        $workflows = Workflow::query()->where('organization_id', $organization->id)->with('statuses')->get();

        foreach ($teams as $team) {
            if (! $workflows->contains('team_id', $team->id)) {
                $workflows->push($this->workflows->forTeam($team));
            }
        }

        return [
            'members' => UserSummaryResource::collection($members)->resolve(),
            'teams' => $teams->map(fn (Team $team) => [
                ...TeamSummaryResource::make($team)->resolve(),
                'workflow_id' => $workflows->firstWhere('team_id', $team->id)?->id,
                'estimate_scale' => $team->estimate_scale,
            ])->values()->all(),
            'products' => $products->map(fn (Product $product) => [
                ...ProductSummaryResource::make($product)->resolve(),
                'workflow_id' => $product->issue_workflow_id ?? $default->id,
            ])->values()->all(),
            'modules' => ModuleResource::collection($modules)->resolve(),
            'projects' => ProjectSummaryResource::collection($projects)->resolve(),
            'milestones' => MilestoneResource::collection($milestones)->resolve(),
            'cycles' => CycleResource::collection($cycles)->resolve(),
            'labels' => IssueLabelResource::collection($labels)->resolve(),
            'statuses' => WorkflowStatusResource::collection($default->statuses)->resolve(),
            'default_workflow_id' => $default->id,
            'workflows' => $workflows->mapWithKeys(fn (Workflow $w) => [$w->id => [
                'id' => $w->id,
                'kind' => $w->kind->value,
                'team_id' => $w->team_id,
                'product_id' => $w->product_id,
                'statuses' => WorkflowStatusResource::collection($w->statuses)->resolve(),
            ]])->all(),
            'priorities' => IssuePriority::options(),
            'categories' => StatusCategory::options(),
            'team_ids' => $this->access->teamIds($user, $organization->id),
        ];
    }
}
