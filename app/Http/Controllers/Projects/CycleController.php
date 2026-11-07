<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\Concerns\BuildsIssueLists;
use App\Http\Resources\Projects\CycleResource;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Http\Resources\Projects\TeamSummaryResource;
use App\Http\Resources\Projects\WorkflowResource;
use App\Models\Organization;
use App\Models\Projects\Cycle;
use App\Models\Projects\Issue;
use App\Models\Team;
use App\Services\Projects\CycleScheduler;
use App\Services\Projects\IssueLookups;
use App\Services\Projects\IssueQuery;
use App\Services\Projects\RecentlyViewed;
use App\Services\Projects\WorkflowActions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CycleController extends Controller
{
    use BuildsIssueLists;

    public function __construct(protected CycleScheduler $cycles, protected IssueLookups $lookups, protected WorkflowActions $workflows, protected RecentlyViewed $recent) {}

    public function show(Request $request, Organization $currentOrganization, Team $team, Cycle $cycle): Response
    {
        abort_unless($team->organization_id === $currentOrganization->id && $cycle->team_id === $team->id, 404);
        $this->authorize('view', $cycle);
        $user = $request->user();
        $this->recent->touch($user, $currentOrganization, $cycle);
        $request->attributes->set('focus_team_id', $team->id);

        $workflow = $this->workflows->forTeam($team);
        $display = $this->display($request, ['group_by' => 'status']);
        $filters = $this->filters($request, [['field' => 'cycle', 'op' => 'in', 'value' => [$cycle->id]]]);

        return Inertia::render('projects/cycles/Show', [
            'team' => [...TeamSummaryResource::make($team)->resolve(), 'can' => ['lead' => $user->can('lead', $team)]],
            'cycle' => CycleResource::make($cycle)->resolve(),
            'summary' => $this->cycles->summary($cycle),
            'velocity' => $this->cycles->velocity($team),
            'siblings' => CycleResource::collection($team->cycles()->orderByDesc('number')->limit(12)->get())->resolve(),
            'workflow' => WorkflowResource::make($workflow)->resolve(),
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
            'filters' => $filters,
            'display' => $display,
            'issues' => $this->issuesProp($request, $user, $filters, array_merge($display, ['show_completed' => 'all']), 100),
            'board' => Inertia::optional(fn () => $this->boardColumns($request, $user, $filters, array_merge($display, ['show_completed' => 'all']), $workflow->statuses, $team->id)),
            'backlog' => Inertia::optional(fn () => IssueListItemResource::collection(
                Issue::query()->visibleTo($user)->with(IssueQuery::EAGER)
                    ->whereHas('workstreams', fn (Builder $w) => $w->where('team_id', $team->id)->whereNull('cycle_id')->whereNotIn('status_category', ['completed', 'canceled']))
                    ->orderByDesc('priority')->orderByDesc('updated_at')->limit(50)->get()
            )->resolve()),
        ]);
    }

    public function store(Request $request, Organization $currentOrganization, Team $team): JsonResponse
    {
        abort_unless($team->organization_id === $currentOrganization->id, 404);
        $this->authorize('lead', $team);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'goals' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        ]);

        $cycle = $this->cycles->create($team, $data, $request->user());

        return response()->json(['cycle' => CycleResource::make($cycle)->resolve()], 201);
    }

    public function update(Request $request, Organization $currentOrganization, Team $team, Cycle $cycle): JsonResponse
    {
        $this->ensure($currentOrganization, $team, $cycle);
        $this->authorize('manage', $cycle);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'goals' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
        ]);

        return response()->json(['cycle' => CycleResource::make($this->cycles->update($cycle, $data, $request->user()))->resolve()]);
    }

    public function start(Request $request, Organization $currentOrganization, Team $team, Cycle $cycle): JsonResponse
    {
        $this->ensure($currentOrganization, $team, $cycle);
        $this->authorize('manage', $cycle);

        return response()->json(['cycle' => CycleResource::make($this->cycles->start($cycle, $request->user()))->resolve()]);
    }

    public function complete(Request $request, Organization $currentOrganization, Team $team, Cycle $cycle): JsonResponse
    {
        $this->ensure($currentOrganization, $team, $cycle);
        $this->authorize('manage', $cycle);

        $data = $request->validate(['carry_over' => ['nullable', 'boolean']]);

        return response()->json(['cycle' => CycleResource::make($this->cycles->complete($cycle, $request->user(), null, isset($data['carry_over']) ? (bool) $data['carry_over'] : null))->resolve()]);
    }

    public function schedule(Request $request, Organization $currentOrganization, Team $team): JsonResponse
    {
        abort_unless($team->organization_id === $currentOrganization->id, 404);
        $this->authorize('lead', $team);

        return response()->json(['cycles' => CycleResource::collection($this->cycles->ensureUpcoming($team))->resolve()]);
    }

    public function destroy(Request $request, Organization $currentOrganization, Team $team, Cycle $cycle): JsonResponse
    {
        $this->ensure($currentOrganization, $team, $cycle);
        $this->authorize('manage', $cycle);

        $this->cycles->delete($cycle, $request->user());

        return response()->json(['deleted' => true]);
    }

    protected function ensure(Organization $organization, Team $team, Cycle $cycle): void
    {
        abort_unless($team->organization_id === $organization->id && $cycle->team_id === $team->id, 404);
    }
}
