<?php

namespace App\Http\Requests\Mail;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateImapAccountRequest extends ImapAccountRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->connectionRules(requirePassword: false),
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
