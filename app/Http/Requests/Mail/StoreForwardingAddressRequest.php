<?php

namespace App\Http\Requests\Mail;

use App\Enums\MailProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreForwardingAddressRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:80'],
            'channel' => ['nullable', 'string', Rule::in(MailProvider::forwardingChannelValues())],
        ];
    }

    public function channel(): MailProvider
    {
        $channel = $this->validated('channel');

        return is_string($channel) ? MailProvider::from($channel) : MailProvider::Forwarding;
    }
}
