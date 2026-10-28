<?php

namespace App\Services\Mail\Data;

final readonly class ConnectionTestResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public bool $ok,
        public ?string $message = null,
        public array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function success(?string $message = null, array $details = []): self
    {
        return new self(true, $message ?? 'Connection successful.', $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function failure(string $message, array $details = []): self
    {
        return new self(false, $message, $details);
    }
}
