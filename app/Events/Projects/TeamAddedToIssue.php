<?php

namespace App\Events\Projects;

use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TeamAddedToIssue
{
    use Dispatchable, SerializesModels;

    public function __construct(public Issue $issue, public IssueWorkstream $workstream, public ?User $actor = null) {}
}
