<?php

namespace App\Events\Conversations;

use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMoved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public Inbox $fromInbox, public Inbox $toInbox,
        public ?User $actor = null,
    ) {}
}
