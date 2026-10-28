<?php

namespace App\Events\Conversations;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public ?User $previousAssignee, public ?User $assignee,
        public ?User $actor = null,
    ) {}
}
