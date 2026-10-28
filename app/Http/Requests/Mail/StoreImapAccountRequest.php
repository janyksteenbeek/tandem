<?php

namespace App\Http\Requests\Mail;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class StoreImapAccountRequest extends ImapAccountRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->connectionRules(),
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'inbox' => ['nullable', 'string', 'uuid'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->emailAddress() === null) {
                    $validator->errors()->add('email', __('Enter the email address of this mailbox; the username is not an email address.'));
                }
            },
        ];
    }

    /**
     * The mailbox address: the explicit email field, or the username when it is an address.
     */
    public function emailAddress(): ?string
    {
        $email = $this->input('email');

        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Str::lower($email);
        }

        $username = $this->input('username');

        if (is_string($username) && filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return Str::lower($username);
        }

        return null;
    }
}
