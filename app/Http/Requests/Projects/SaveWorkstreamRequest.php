<?php

namespace App\Http\Requests\Projects;

use App\Http\Requests\OrganizationScopedRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class SaveWorkstreamRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = $this->organization();

        return [
            'team_id' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', Rule::exists('teams', 'id')->where('organization_id', $organization->id)],
            'responsibility' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['nullable', 'boolean'],
            'estimate' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'due_date' => ['nullable', 'date'],
            'cycle_id' => ['nullable', 'integer', Rule::exists('cycles', 'id')],
            'assignee_ids' => ['nullable', 'array', 'max:20'],
            'assignee_ids.*' => ['integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $organization->id)],
        ];
    }
}
