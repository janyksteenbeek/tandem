<?php

namespace App\Events\Projects;

use App\Models\Mail\Conversation;
use App\Models\Projects\Issue;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationLinkedToIssue
{
    use Dispatchable, SerializesModels;

    public function __construct(public Issue $issue, public Conversation $conversation, public ?User $actor = null) {}
}
