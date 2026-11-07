<?php

namespace App\Http\Requests\Projects;

use App\Enums\Projects\IssuePriority;
use App\Http\Requests\OrganizationScopedRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class SaveIssueRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = $this->organization();
        $orgScoped = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $organization->id);

        return [
            'title' => ['required', 'string', 'max:255'],
            'description_html' => ['nullable', 'string', 'max:200000'],
            'product_id' => ['nullable', 'integer', $orgScoped('products')],
            'project_id' => ['nullable', 'integer', $orgScoped('projects')],
            'milestone_id' => ['nullable', 'integer', $orgScoped('milestones')],
            'parent_id' => ['nullable', 'integer', $orgScoped('issues')],
            'status_id' => ['nullable', 'integer', Rule::exists('workflow_statuses', 'id')],
            'priority' => ['nullable', 'string', Rule::in(array_map(fn (IssuePriority $p) => $p->key(), IssuePriority::cases()))],
            'owner_id' => ['nullable', 'integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $organization->id)],
            'lead_team_id' => ['nullable', 'integer', $orgScoped('teams')],
            'label_ids' => ['nullable', 'array', 'max:20'],
            'label_ids.*' => ['integer'],
            'module_ids' => ['nullable', 'array', 'max:20'],
            'module_ids.*' => ['integer'],
            'estimate' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'teams' => ['nullable', 'array', 'max:20'],
            'teams.*.team_id' => ['required', 'integer', $orgScoped('teams')],
            'teams.*.responsibility' => ['nullable', 'string', 'max:2000'],
            'teams.*.is_required' => ['nullable', 'boolean'],
            'teams.*.assignee_ids' => ['nullable', 'array', 'max:20'],
            'teams.*.assignee_ids.*' => ['integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $organization->id)],
            'conversation_uuid' => ['nullable', 'string', 'uuid'],
        ];
    }
}
