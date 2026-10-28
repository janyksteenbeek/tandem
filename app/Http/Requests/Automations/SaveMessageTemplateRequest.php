<?php

namespace App\Http\Requests\Automations;

use App\Models\Inbox;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class SaveMessageTemplateRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:200000'],
            'inbox_uuid' => ['nullable', 'string', Rule::exists('inboxes', 'uuid')->where('organization_id', $this->organization()->id)->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function templatePayload(): array
    {
        $inbox = $this->validated('inbox_uuid')
            ? Inbox::query()->where('organization_id', $this->organization()->id)->where('uuid', $this->validated('inbox_uuid'))->first()
            : null;

        return [
            'inbox_id' => $inbox?->id,
            'name' => $this->validated('name'),
            'category' => $this->validated('category'),
            'subject' => $this->validated('subject'),
            'body_html' => $this->validated('body_html'),
        ];
    }
}
