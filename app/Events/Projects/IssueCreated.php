<?php

namespace App\Events\Projects;

use App\Models\Projects\Issue;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IssueCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Issue $issue, public ?User $actor = null, public string $source = 'manual') {}
}
