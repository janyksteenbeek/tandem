<?php

namespace App\Http\Requests\Mail;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Payload posted by the Cloudflare Email Worker. Authentication happens in
 * VerifyCloudflareWorkerSignature; this request only validates the shape.
 */
class CloudflareInboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:191'],
            'to' => ['required', 'string', 'max:320'],
            'from' => ['nullable', 'string', 'max:320'],
            'size' => ['required', 'integer', 'min:0'],
            'raw' => ['required', 'string'],
            'received_at' => ['nullable', 'date'],
            'headers' => ['nullable', 'array', 'max:20'],
            'headers.*' => ['nullable', 'string', 'max:2000'],
            'hops' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function hops(): int
    {
        return (int) ($this->validated('hops') ?? 0);
    }

    /**
     * @return array<string, string|null>
     */
    public function interestingHeaders(): array
    {
        $headers = $this->validated('headers');

        return is_array($headers) ? array_change_key_case(array_filter($headers, fn ($value) => is_string($value) || $value === null), CASE_LOWER) : [];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'invalid',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
