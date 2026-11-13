<?php

namespace App\Http\Requests\Projects;

use App\Enums\Projects\ProjectHealth;
use App\Enums\Projects\ProjectStatus;
use App\Enums\Projects\ProjectVisibility;
use App\Http\Requests\OrganizationScopedRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class SaveProjectRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = $this->organization();

        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:140'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('organization_id', $organization->id)],
            'description_html' => ['nullable', 'string', 'max:200000'],
            'icon' => ['nullable', 'string', 'max:32'],
            'color' => ['nullable', 'string', 'max:32'],
            'lead_id' => ['nullable', 'integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $organization->id)],
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'health' => ['nullable', Rule::enum(ProjectHealth::class)],
            'priority' => ['nullable', 'integer', 'min:0', 'max:4'],
            'visibility' => ['nullable', Rule::enum(ProjectVisibility::class)],
            'start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')->where('organization_id', $organization->id)],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
            'module_ids' => ['nullable', 'array'],
            'module_ids.*' => ['integer'],
        ];
    }
}
