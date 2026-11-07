<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SaveProjectSettingsRequest;
use App\Http\Resources\Projects\WorkflowResource;
use App\Models\Organization;
use App\Models\Projects\IssueSequence;
use App\Models\Projects\Product;
use App\Services\Audit\AuditLogger;
use App\Services\Projects\IssueIdentifiers;
use App\Services\Projects\WorkflowActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization-level project settings: the organization's issue key, the default issue workflow
 * and the team workflows.
 */
class ProjectSettingsController extends Controller
{
    public function __construct(protected WorkflowActions $workflows, protected IssueIdentifiers $identifiers, protected AuditLogger $audit) {}

    public function edit(Request $request, Organization $currentOrganization): Response
    {
        $this->authorize('manage', $currentOrganization);

        $sequences = IssueSequence::query()->where('organization_id', $currentOrganization->id)->get()->keyBy('scope_key');

        return Inertia::render('settings/projects/Index', [
            'organization' => ['id' => $currentOrganization->id, 'name' => $currentOrganization->name, 'slug' => $currentOrganization->slug],
            'issueKey' => $currentOrganization->issue_key,
            'derivedKey' => IssueIdentifiers::deriveKey($currentOrganization->name),
            'nextNumber' => (int) ($sequences['org']->next_number ?? 1),
            'workflow' => WorkflowResource::make($this->workflows->defaultIssueWorkflow($currentOrganization))->resolve(),
            'products' => Product::query()->where('organization_id', $currentOrganization->id)->orderBy('name')->get()->map(fn (Product $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'key' => $p->key,
                'color' => $p->color,
                'next_number' => (int) ($sequences['product:'.$p->id]->next_number ?? 1),
                'has_own_workflow' => $p->issue_workflow_id !== null,
            ])->values()->all(),
            'teams' => $currentOrganization->teams()->get()->map(fn ($team) => [
                'id' => $team->id,
                'slug' => $team->slug,
                'name' => $team->name,
                'color' => $team->color,
                'workflow' => WorkflowResource::make($this->workflows->forTeam($team))->resolve(),
                'cycle_settings' => $team->cycleSettings(),
                'estimate_scale' => $team->estimate_scale,
            ])->values()->all(),
        ]);
    }

    public function update(SaveProjectSettingsRequest $request, Organization $currentOrganization): RedirectResponse
    {
        $this->authorize('manage', $currentOrganization);
        $data = $request->validated();

        if (array_key_exists('issue_key', $data)) {
            $currentOrganization->forceFill(['issue_key' => filled($data['issue_key']) ? strtoupper((string) $data['issue_key']) : null])->save();
        }

        $workflow = $this->workflows->defaultIssueWorkflow($currentOrganization);
        $flags = array_intersect_key($data, array_flip(['auto_start', 'auto_complete']));

        if ($flags !== []) {
            $this->workflows->updateWorkflow($workflow, $flags, $request->user());
        }

        $this->audit->record('organization.project_settings_updated', $currentOrganization, $request->user(), $currentOrganization, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project settings saved')]);

        return back();
    }
}
