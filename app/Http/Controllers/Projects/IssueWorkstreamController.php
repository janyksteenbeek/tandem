<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SaveWorkstreamRequest;
use App\Http\Resources\Projects\IssueResource;
use App\Models\Organization;
use App\Models\Projects\Cycle;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;
use App\Models\Projects\WorkflowStatus;
use App\Models\Team;
use App\Services\Projects\IssueActions;
use App\Services\Projects\IssueQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IssueWorkstreamController extends Controller
{
    public function __construct(protected IssueActions $actions) {}

    public function store(SaveWorkstreamRequest $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('addTeam', $issue);
        $data = $request->validated();

        $this->actions->addTeam($issue, Team::query()->whereKey($data['team_id'])->firstOrFail(), $request->user(), [
            'responsibility' => $data['responsibility'] ?? null,
            'is_required' => $data['is_required'] ?? true,
            'assignees' => $data['assignee_ids'] ?? [],
            'cycle' => isset($data['cycle_id']) ? Cycle::query()->whereKey($data['cycle_id'])->first() : null,
            'estimate' => $data['estimate'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ]);

        return $this->respond($issue, 201);
    }

    public function update(SaveWorkstreamRequest $request, Organization $currentOrganization, Issue $issue, IssueWorkstream $workstream): JsonResponse
    {
        $this->ensureBelongs($issue, $workstream);
        $this->authorize('updateWorkstream', [$issue, $workstream]);
        $data = $request->validated();

        if (array_key_exists('is_required', $data) && $data['is_required'] !== null && (bool) $data['is_required'] !== $workstream->is_required) {
            $this->authorize('setWorkstreamRequired', $issue);
        }

        $this->actions->updateWorkstream($workstream, array_intersect_key($data, array_flip(['responsibility', 'is_required', 'estimate', 'due_date'])), $request->user());

        if (array_key_exists('assignee_ids', $data) && $data['assignee_ids'] !== null) {
            $this->actions->syncAssignees($workstream, array_map('intval', $data['assignee_ids']), $request->user());
        }

        return $this->respond($issue);
    }

    public function status(Request $request, Organization $currentOrganization, Issue $issue, IssueWorkstream $workstream): JsonResponse
    {
        $this->ensureBelongs($issue, $workstream);
        $this->authorize('updateWorkstream', [$issue, $workstream]);

        $data = $request->validate([
            'status_id' => ['required', 'integer', Rule::exists('workflow_statuses', 'id')],
            'force' => ['nullable', 'boolean'],
        ]);

        $this->actions->setWorkstreamStatus($workstream, WorkflowStatus::query()->whereKey($data['status_id'])->firstOrFail(), $request->user(), 'manual', (bool) ($data['force'] ?? false));

        return $this->respond($issue);
    }

    public function assignees(Request $request, Organization $currentOrganization, Issue $issue, IssueWorkstream $workstream): JsonResponse
    {
        $this->ensureBelongs($issue, $workstream);
        $this->authorize('updateWorkstream', [$issue, $workstream]);

        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:20'],
            'user_ids.*' => ['integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $currentOrganization->id)],
        ]);

        $this->actions->syncAssignees($workstream, array_map('intval', $data['user_ids']), $request->user());

        return $this->respond($issue);
    }

    public function cycle(Request $request, Organization $currentOrganization, Issue $issue, IssueWorkstream $workstream): JsonResponse
    {
        $this->ensureBelongs($issue, $workstream);
        $this->authorize('updateWorkstream', [$issue, $workstream]);

        $data = $request->validate(['cycle_id' => ['nullable', 'integer', Rule::exists('cycles', 'id')->where('team_id', $workstream->team_id)]]);
        $this->actions->setWorkstreamCycle($workstream, isset($data['cycle_id']) ? Cycle::query()->whereKey($data['cycle_id'])->first() : null, $request->user());

        return $this->respond($issue);
    }

    public function destroy(Request $request, Organization $currentOrganization, Issue $issue, IssueWorkstream $workstream): JsonResponse
    {
        $this->ensureBelongs($issue, $workstream);
        $this->authorize('removeTeam', $issue);

        $this->actions->removeTeam($issue, $workstream->team, $request->user());

        return $this->respond($issue);
    }

    protected function ensureBelongs(Issue $issue, IssueWorkstream $workstream): void
    {
        abort_unless($workstream->issue_id === $issue->id, 404);
    }

    protected function respond(Issue $issue, int $status = 200): JsonResponse
    {
        return response()->json(['issue' => IssueResource::make($issue->fresh(IssueQuery::EAGER))->resolve()], $status);
    }
}
