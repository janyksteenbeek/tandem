<?php

namespace App\Events\Projects;

use App\Models\Projects\Issue;
use App\Models\Projects\WorkflowStatus;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IssueStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public Issue $issue, public WorkflowStatus $from, public WorkflowStatus $to, public ?User $actor = null, public string $source = 'manual') {}
}
