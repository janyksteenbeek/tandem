<?php

namespace App\Http\Requests\Automations;

use App\Enums\AutomationConditionField;
use App\Enums\AutomationConditionOperator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Dry-run request: evaluates the rule's stored conditions (or an unsaved override) against a conversation.
 */
class TestAutomationRuleRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'conversation_uuid' => ['required', 'string', Rule::exists('conversations', 'uuid')->where('organization_id', $this->organization()->id)->whereNull('deleted_at')],
            'match' => ['sometimes', Rule::in(['all', 'any'])],
            'conditions' => ['sometimes', 'array', 'max:25'],
            'conditions.*.field' => ['required_with:conditions', Rule::enum(AutomationConditionField::class)],
            'conditions.*.operator' => ['required_with:conditions', Rule::enum(AutomationConditionOperator::class)],
            'conditions.*.value' => ['nullable'],
        ];
    }
}
