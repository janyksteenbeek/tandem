<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SaveWorkflowStatusRequest;
use App\Http\Resources\Projects\WorkflowResource;
use App\Models\Organization;
use App\Models\Projects\Workflow;
use App\Models\Projects\WorkflowStatus;
use App\Services\Projects\WorkflowActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Edits one workflow: statuses, their order, the default, transitions and completion flags.
 * Works for team workflows and product/organization issue workflows alike.
 */
class WorkflowController extends Controller
{
    public function __construct(protected WorkflowActions $workflows) {}

    public function show(Request $request, Organization $currentOrganization, Workflow $workflow): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $workflow);
        $this->authorize('view', $workflow);

        return $this->respond($workflow);
    }

    public function update(Request $request, Organization $currentOrganization, Workflow $workflow): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $workflow);
        $this->authorize('manage', $workflow);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'auto_start' => ['sometimes', 'boolean'],
            'auto_complete' => ['sometimes', 'boolean'],
        ]);

        $this->workflows->updateWorkflow($workflow, $data, $request->user());

        return $this->respond($workflow);
    }

    public function storeStatus(SaveWorkflowStatusRequest $request, Organization $currentOrganization, Workflow $workflow): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $workflow);
        $this->authorize('manage', $workflow);

        $this->workflows->createStatus($workflow, $request->validated(), $request->user());

        return $this->respond($workflow, 201);
    }

    public function updateStatus(SaveWorkflowStatusRequest $request, Organization $currentOrganization, Workflow $workflow, WorkflowStatus $status): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $workflow);
        abort_unless($status->workflow_id === $workflow->id, 404);
        $this->authorize('manage', $workflow);

        $this->workflows->updateStatus($status, $request->validated(), $request->user());

        return $this->respond($workflow);
    }

    public function reorder(Request $request, Organization $currentOrganization, Workflow $workflow): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $workflow);
        $this->authorize('manage', $workflow);

        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', Rule::exists('workflow_statuses', 'id')->where('workflow_id', $workflow->id)]]);
        $this->workflows->reorder($workflow, array_map('intval', $data['ids']), $request->user());

        return $this->respond($workflow);
    }

    public function setDefault(Request $request, Organization $currentOrganization, Workflow $workflow, WorkflowStatus $status): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $workflow);
        abort_unless($status->workflow_id === $workflow->id, 404);
        $this->authorize('manage', $workflow);

        $this->workflows->setDefault($workflow, $status, $request->user());

        return $this->respond($workflow);
    }

    public function destroyStatus(Request $request, Organization $currentOrganization, Workflow $workflow, WorkflowStatus $status): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $workflow);
        abort_unless($status->workflow_id === $workflow->id, 404);
        $this->authorize('manage', $workflow);

        $data = $request->validate(['replacement_status_id' => ['required', 'integer', Rule::exists('workflow_statuses', 'id')->where('workflow_id', $workflow->id)]]);
        $remapped = $this->workflows->deleteStatus($status, WorkflowStatus::query()->whereKey($data['replacement_status_id'])->firstOrFail(), $request->user());

        return response()->json(['workflow' => WorkflowResource::make($workflow->fresh(['statuses', 'transitions']))->resolve(), 'remapped' => $remapped]);
    }

    public function transitions(Request $request, Organization $currentOrganization, Workflow $workflow): JsonResponse
    {
        $this->ensureBelongs($currentOrganization, $workflow);
        $this->authorize('manage', $workflow);

        $data = $request->validate([
            'transitions' => ['present', 'array'],
            'transitions.*.from' => ['required', 'integer', Rule::exists('workflow_statuses', 'id')->where('workflow_id', $workflow->id)],
            'transitions.*.to' => ['required', 'integer', Rule::exists('workflow_statuses', 'id')->where('workflow_id', $workflow->id)],
        ]);

        $this->workflows->setTransitions($workflow, $data['transitions'], $request->user());

        return $this->respond($workflow);
    }

    protected function ensureBelongs(Organization $organization, Workflow $workflow): void
    {
        abort_unless($workflow->organization_id === $organization->id, 404);
    }

    protected function respond(Workflow $workflow, int $status = 200): JsonResponse
    {
        return response()->json(['workflow' => WorkflowResource::make($workflow->fresh(['statuses', 'transitions']))->resolve()], $status);
    }
}
