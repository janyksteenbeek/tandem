<?php

namespace App\Events\Projects;

use App\Models\Projects\Issue;
use App\Models\Projects\IssueComment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IssueCommentPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Issue $issue, public IssueComment $comment, public ?User $author = null) {}
}
