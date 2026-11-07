<?php

namespace App\Http\Controllers\Projects;

use App\Enums\Projects\IssueRelationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Projects\IssueResource;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueRelation;
use App\Models\Projects\IssueWorkstream;
use App\Models\Projects\WorkstreamDependency;
use App\Services\Projects\DependencyGraph;
use App\Services\Projects\IssueActions;
use App\Services\Projects\IssueQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Issue ↔ issue relations and workstream dependencies.
 */
class IssueRelationController extends Controller
{
    public function __construct(protected IssueActions $actions, protected DependencyGraph $graph) {}

    public function store(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('update', $issue);

        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:24'],
            'kind' => ['required', Rule::in(['blocks', 'blocked_by', 'related', 'duplicate'])],
        ]);

        $other = Issue::query()->where('organization_id', $currentOrganization->id)->where('identifier', strtoupper($data['identifier']))->firstOrFail();
        $this->authorize('view', $other);

        match ($data['kind']) {
            'blocks' => $this->actions->addRelation($issue, $other, IssueRelationType::Blocks, $request->user()),
            'blocked_by' => $this->actions->addRelation($other, $issue, IssueRelationType::Blocks, $request->user()),
            'duplicate' => $this->actions->addRelation($issue, $other, IssueRelationType::Duplicate, $request->user()),
            default => $this->actions->addRelation($issue, $other, IssueRelationType::Related, $request->user()),
        };

        return $this->respond($issue);
    }

    public function destroy(Request $request, Organization $currentOrganization, Issue $issue, IssueRelation $relation): JsonResponse
    {
        abort_unless($relation->issue_id === $issue->id || $relation->related_issue_id === $issue->id, 404);
        $this->authorize('update', $issue);

        $this->actions->removeRelation($relation, $request->user());

        return $this->respond($issue);
    }

    public function storeDependency(Request $request, Organization $currentOrganization, Issue $issue, IssueWorkstream $workstream): JsonResponse
    {
        abort_unless($workstream->issue_id === $issue->id, 404);
        $this->authorize('updateWorkstream', [$issue, $workstream]);

        $data = $request->validate([
            'workstream_uuid' => ['nullable', 'string', 'uuid'],
            'identifier' => ['nullable', 'string', 'max:24'],
        ]);

        if (! empty($data['workstream_uuid'])) {
            $target = IssueWorkstream::query()->where('uuid', $data['workstream_uuid'])->with('issue')->firstOrFail();
            $this->authorize('view', $target->issue);
        } else {
            $target = Issue::query()->where('organization_id', $currentOrganization->id)->where('identifier', strtoupper((string) ($data['identifier'] ?? '')))->firstOrFail();
            $this->authorize('view', $target);
        }

        $this->graph->addWorkstreamDependency($workstream, $target, $request->user(), $this->actions);

        return $this->respond($issue);
    }

    public function destroyDependency(Request $request, Organization $currentOrganization, Issue $issue, IssueWorkstream $workstream, WorkstreamDependency $dependency): JsonResponse
    {
        abort_unless($workstream->issue_id === $issue->id && $dependency->workstream_id === $workstream->id, 404);
        $this->authorize('updateWorkstream', [$issue, $workstream]);

        $this->graph->removeWorkstreamDependency($dependency, $request->user(), $this->actions);

        return $this->respond($issue);
    }

    protected function respond(Issue $issue): JsonResponse
    {
        return response()->json(['issue' => IssueResource::make($issue->fresh(IssueQuery::EAGER))->resolve()]);
    }
}
