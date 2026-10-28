<?php

namespace App\Http\Requests\Automations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ReorderAutomationRulesRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rules' => ['required', 'array', 'min:1'],
            'rules.*' => ['required', 'string', 'distinct', Rule::exists('automation_rules', 'uuid')->where('organization_id', $this->organization()->id)->whereNull('deleted_at')],
        ];
    }
}
