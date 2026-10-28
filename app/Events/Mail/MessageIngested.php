<?php

namespace App\Events\Mail;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a message has been durably stored. Listeners run automations and broadcast
 * realtime updates; the ingestor itself never knows about either.
 */
class MessageIngested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Message $message,
        public Conversation $conversation,
        public bool $isNewConversation,
        public bool $reopened = false,
    ) {}
}
