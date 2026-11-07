<?php

namespace App\Events\Mail;

use App\Models\Mail\Conversation;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationTagged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public Tag $tag,
        public ?User $actor = null,
    ) {}
}
