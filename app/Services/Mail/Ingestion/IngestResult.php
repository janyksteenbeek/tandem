<?php

namespace App\Services\Mail\Ingestion;

use App\Models\Conversation;
use App\Models\Message;

final readonly class IngestResult
{
    public function __construct(
        public Message $message,
        public Conversation $conversation,
        public bool $created,
        public bool $createdConversation,
    ) {}
}
