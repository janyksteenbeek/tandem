<?php

namespace App\Events\Projects;

use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DependencyResolved
{
    use Dispatchable, SerializesModels;

    public function __construct(public Issue $issue, public ?IssueWorkstream $workstream, public Issue|IssueWorkstream $resolvedBy) {}
}
