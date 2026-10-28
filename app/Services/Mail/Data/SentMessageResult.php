<?php

namespace App\Services\Mail\Data;

final readonly class SentMessageResult
{
    public function __construct(
        public string $messageId,
        public ?string $providerMessageId = null,
        public ?string $providerThreadId = null,
    ) {}
}
