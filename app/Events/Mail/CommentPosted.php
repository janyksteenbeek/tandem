<?php

namespace App\Events\Mail;

use App\Models\Mail\Comment;
use App\Models\Mail\Conversation;
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
