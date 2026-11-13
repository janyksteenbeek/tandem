<?php

namespace App\Services\Projects;

use App\Enums\Projects\CycleStatus;
use App\Enums\Projects\IssueViewScope;
use App\Http\Resources\Projects\CycleResource;
use App\Http\Resources\Projects\ProductSummaryResource;
use App\Http\Resources\Projects\ProjectSummaryResource;
use App\Http\Resources\Projects\TeamSummaryResource;
use App\Models\Organization;
use App\Models\Projects\Cycle;
use App\Models\Projects\Favorite;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueView;
use App\Models\Projects\Module;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Projects\RecentView;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Sidebar data for the Projects section: counts for the current user, their favorites and recent
 * items, and the products, projects, teams, cycles and saved views they can see. Teams only count
 * once they do project work (a product, a project, a workstream or cycles); mail-only teams stay out.
 */
class ProjectsNavigation
{
    public function __construct(protected IssueAccess $access, protected Favorites $favorites, protected RecentlyViewed $recent) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, Organization $organization): array
    {
        $visibleIssues = fn () => Issue::query()->visibleTo($user);
        $teamIds = $this->access->teamIds($user, $organization->id);

        $open = ['completed', 'canceled'];

        $assigned = (clone $visibleIssues())->whereNotIn('issues.status_category', $open)
            ->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('issue_workstream_assignees')
                ->join('issue_workstreams', 'issue_workstreams.id', '=', 'issue_workstream_assignees.workstream_id')
                ->whereColumn('issue_workstreams.issue_id', 'issues.id')
                ->where('issue_workstream_assignees.user_id', $user->id))
            ->count();

        $owned = (clone $visibleIssues())->whereNotIn('issues.status_category', $open)->where('issues.owner_id', $user->id)->count();

        $dueToday = (clone $visibleIssues())->whereNotIn('issues.status_category', $open)
            ->where(fn ($q) => $q->where('issues.owner_id', $user->id)->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('issue_workstream_assignees')
                ->join('issue_workstreams', 'issue_workstreams.id', '=', 'issue_workstream_assignees.workstream_id')
                ->whereColumn('issue_workstreams.issue_id', 'issues.id')
                ->where('issue_workstream_assignees.user_id', $user->id)))
            ->whereDate('issues.due_date', '<=', now()->toDateString())
            ->count();

        $triage = (clone $visibleIssues())->where('issues.status_category', 'backlog')->whereNull('issues.owner_id')->doesntHave('workstreams')->count();

        $products = Product::query()->where('organization_id', $organization->id)->active()->with('modules')->orderBy('name')->get()
            ->filter(fn (Product $product) => $this->access->productLevelFor($user, $product) !== null)
            ->values();

        $projects = Project::query()->tap(fn ($q) => $this->access->applyProjectVisibility($q, $user, $organization->id))
            ->whereNotIn('status', ['completed', 'canceled'])
            ->with(['lead', 'product'])
            ->orderBy('name')
            ->get();

        $teams = $organization->teams()
            ->where(fn ($q) => $q->whereHas('products')->orWhereHas('projects')->orWhereHas('workstreams')->orWhere('cycle_settings->enabled', true))
            ->with(['cycles' => fn ($q) => $q->where('status', CycleStatus::Active->value)])
            ->get();

        $cycles = Cycle::query()->whereIn('team_id', $teamIds)->where('status', CycleStatus::Active->value)->with('team')->get();

        $views = IssueView::query()->where('organization_id', $organization->id)
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('scope', IssueViewScope::Personal->value)->where('user_id', $user->id))
                ->orWhere(fn ($t) => $t->where('scope', IssueViewScope::Team->value)->whereIn('team_id', $teamIds ?: [0]))
                ->orWhere('scope', IssueViewScope::Organization->value))
            ->orderBy('position')->orderBy('name')
            ->get();

        return [
            'counts' => [
                'assigned' => $assigned,
                'owned' => $owned,
                'due_today' => $dueToday,
                'triage' => $triage,
            ],
            'favorites' => $this->favorites->forUser($user, $organization)->map(fn (Favorite $f) => $this->entity($f->favoritable, $organization))->filter()->values()->all(),
            'recent' => $this->recent->forUser($user, $organization)->map(fn (RecentView $r) => $this->entity($r->viewable, $organization))->filter()->values()->all(),
            'products' => $products->map(fn (Product $product) => [
                ...ProductSummaryResource::make($product)->resolve(),
                'modules' => $product->modules->map(fn (Module $m) => ['id' => $m->id, 'slug' => $m->slug, 'name' => $m->name, 'color' => $m->color, 'icon' => $m->icon, 'parent_id' => $m->parent_id])->values()->all(),
            ])->values()->all(),
            'projects' => ProjectSummaryResource::collection($projects)->resolve(),
            'teams' => $teams->map(fn (Team $team) => [
                ...TeamSummaryResource::make($team)->resolve(),
                'is_member' => in_array($team->id, $teamIds, true),
                'active_cycle' => $team->cycles->first() ? CycleResource::make($team->cycles->first())->resolve() : null,
            ])->values()->all(),
            'cycles' => $cycles->map(fn (Cycle $cycle) => [...CycleResource::make($cycle)->resolve(), 'team' => TeamSummaryResource::make($cycle->team)->resolve()])->values()->all(),
            'views' => $views->map(fn (IssueView $view) => [
                'id' => $view->id,
                'uuid' => $view->uuid,
                'name' => $view->name,
                'icon' => $view->icon,
                'color' => $view->color,
                'scope' => $view->scope->value,
                'team_id' => $view->team_id,
                'filters' => $view->filters,
                'display' => $view->display,
            ])->values()->all(),
            'team_ids' => $teamIds,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entity(?Model $model, Organization $organization): ?array
    {
        $slug = $organization->slug;

        return match (true) {
            $model instanceof Product => ['type' => 'product', 'key' => 'product:'.$model->id, 'id' => $model->id, 'name' => $model->name, 'color' => $model->color, 'icon' => $model->icon, 'url' => "/$slug/products/$model->slug"],
            $model instanceof Module => ['type' => 'module', 'key' => 'module:'.$model->id, 'id' => $model->id, 'name' => $model->name, 'color' => $model->color, 'icon' => $model->icon, 'url' => "/$slug/products/{$model->product->slug}/modules/$model->slug"],
            $model instanceof Project => ['type' => 'project', 'key' => 'project:'.$model->id, 'id' => $model->id, 'name' => $model->name, 'color' => $model->color, 'icon' => $model->icon, 'url' => "/$slug/projects/$model->slug"],
            $model instanceof Team => ['type' => 'team', 'key' => 'team:'.$model->id, 'id' => $model->id, 'name' => $model->name, 'color' => $model->color, 'icon' => $model->icon, 'url' => "/$slug/teams/$model->slug"],
            $model instanceof Cycle => ['type' => 'cycle', 'key' => 'cycle:'.$model->id, 'id' => $model->id, 'name' => $model->team->name.' · '.$model->displayName(), 'color' => $model->team->color, 'icon' => null, 'url' => "/$slug/teams/{$model->team->slug}/cycles/$model->uuid"],
            $model instanceof Issue => ['type' => 'issue', 'key' => 'issue:'.$model->id, 'id' => $model->id, 'name' => $model->title, 'identifier' => $model->identifier, 'color' => null, 'icon' => null, 'url' => "/$slug/issues/$model->identifier"],
            $model instanceof IssueView => ['type' => 'view', 'key' => 'view:'.$model->id, 'id' => $model->id, 'name' => $model->name, 'color' => $model->color, 'icon' => $model->icon, 'url' => "/$slug/views/$model->uuid"],
            default => null,
        };
    }
}
