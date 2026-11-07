<?php

namespace App\Services\Mail\Ingestion;

use App\Models\Mail\Conversation;
use App\Models\Mail\Message;

final readonly class IngestResult
{
    public function __construct(
        public Message $message,
        public Conversation $conversation,
        public bool $created,
        public bool $createdConversation,
    ) {}
}
