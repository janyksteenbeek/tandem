<?php

namespace App\Events\Mail;

use App\Enums\Mail\ConversationStatus;
use App\Models\Mail\Conversation;
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
