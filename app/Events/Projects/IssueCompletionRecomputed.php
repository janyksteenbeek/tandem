<?php

namespace App\Events\Projects;

use App\Models\Projects\Issue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IssueCompletionRecomputed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Issue $issue, public bool $ready) {}
}
