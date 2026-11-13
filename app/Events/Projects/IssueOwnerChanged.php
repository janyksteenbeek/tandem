<?php

namespace App\Events\Projects;

use App\Models\Projects\Issue;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IssueOwnerChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public Issue $issue, public ?User $owner, public ?User $previous, public ?User $actor = null) {}
}
