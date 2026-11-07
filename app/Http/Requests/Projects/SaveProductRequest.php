<?php

namespace App\Http\Requests\Projects;

use App\Enums\Projects\ProductStatus;
use App\Http\Requests\OrganizationScopedRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class SaveProductRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = $this->organization();
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'key' => [$creating ? 'required' : 'sometimes', 'string', 'regex:/^[A-Za-z][A-Za-z0-9]{1,9}$/'],
            'description' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', 'string', 'max:32'],
            'color' => ['nullable', 'string', 'max:32'],
            'owner_id' => ['nullable', 'integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $organization->id)],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')->where('organization_id', $organization->id)],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
        ];
    }
}
