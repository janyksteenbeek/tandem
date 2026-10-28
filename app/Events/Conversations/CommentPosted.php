<?php

namespace App\Events\Conversations;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public Comment $comment,
        public ?User $actor = null,
    ) {}
}
