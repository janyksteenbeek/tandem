<?php

namespace App\Http\Requests\Projects;

use App\Http\Requests\OrganizationScopedRequest;
use App\Services\Projects\IssueIdentifiers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class SaveProjectSettingsRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'issue_key' => ['nullable', 'string', 'regex:/^[A-Za-z][A-Za-z0-9]{1,9}$/'],
            'auto_start' => ['nullable', 'boolean'],
            'auto_complete' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $key = $this->input('issue_key');

                if (filled($key) && ! app(IssueIdentifiers::class)->isOrganizationKeyAvailable($this->organization(), (string) $key)) {
                    $validator->errors()->add('issue_key', 'A product already uses that key.');
                }
            },
        ];
    }
}
