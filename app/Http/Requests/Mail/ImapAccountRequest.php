<?php

namespace App\Http\Requests\Mail;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for IMAP/SMTP connection details.
 */
abstract class ImapAccountRequest extends FormRequest
{
    /** @var array<int, string> */
    public const array ENCRYPTIONS = ['ssl', 'tls', 'starttls', 'none'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function connectionRules(bool $requirePassword = true): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'string', Rule::in(self::ENCRYPTIONS)],
            'username' => ['required', 'string', 'max:255'],
            'password' => [$requirePassword ? 'required' : 'nullable', 'string', 'max:1024'],
            'validate_cert' => ['sometimes', 'boolean'],
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'string', Rule::in(self::ENCRYPTIONS)],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:1024'],
            'append_sent' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{host: string, port: int, encryption: string, username: string, validate_cert: bool}
     */
    public function imapSettings(): array
    {
        return [
            'host' => (string) $this->validated('host'),
            'port' => (int) $this->validated('port'),
            'encryption' => (string) $this->validated('encryption'),
            'username' => (string) $this->validated('username'),
            'validate_cert' => (bool) ($this->validated('validate_cert') ?? true),
        ];
    }

    /**
     * @return array{host: string, port: int, encryption: string, username: string, append_sent: bool}
     */
    public function smtpSettings(): array
    {
        return [
            'host' => (string) $this->validated('smtp_host'),
            'port' => (int) $this->validated('smtp_port'),
            'encryption' => (string) $this->validated('smtp_encryption'),
            'username' => (string) ($this->validated('smtp_username') ?: $this->validated('username')),
            'append_sent' => (bool) ($this->validated('append_sent') ?? true),
        ];
    }

    public function imapPassword(): ?string
    {
        $password = $this->validated('password');

        return is_string($password) && $password !== '' ? $password : null;
    }

    public function smtpPassword(): ?string
    {
        $password = $this->validated('smtp_password');

        return is_string($password) && $password !== '' ? $password : null;
    }
}
