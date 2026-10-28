<?php

namespace App\Http\Requests\Automations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class PreviewMessageTemplateRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:200000'],
            'conversation_uuid' => ['nullable', 'string', Rule::exists('conversations', 'uuid')->where('organization_id', $this->organization()->id)->whereNull('deleted_at')],
        ];
    }
}
