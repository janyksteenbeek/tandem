<?php

namespace App\Services\Mail\Exceptions;

class RateLimitedException extends ProviderException
{
    public function __construct(string $message = 'Rate limited by provider.', public readonly int $retryAfterSeconds = 60)
    {
        parent::__construct($message);
    }
}
