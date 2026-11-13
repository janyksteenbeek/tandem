<?php

namespace App\Events\Mail;

use App\Models\Mail\Conversation;
use App\Models\Mail\Inbox;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationShared
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public Inbox $sharedWith,
        public ?User $actor = null,
    ) {}
}
