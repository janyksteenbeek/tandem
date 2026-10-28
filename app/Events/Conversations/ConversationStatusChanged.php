<?php

namespace App\Events\Conversations;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public ConversationStatus $from, public ConversationStatus $to,
        public ?User $actor = null,
    ) {}
}
