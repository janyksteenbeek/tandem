<?php

namespace App\Http\Requests\Projects;

use App\Enums\Projects\StatusCategory;
use App\Http\Requests\OrganizationScopedRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class SaveWorkflowStatusRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:60'],
            'category' => [$this->isMethod('POST') ? 'required' : 'sometimes', Rule::enum(StatusCategory::class)],
            'color' => ['nullable', 'string', 'max:32'],
            'icon' => ['nullable', 'string', 'max:32'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
