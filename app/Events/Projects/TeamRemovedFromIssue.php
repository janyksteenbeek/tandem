<?php

namespace App\Events\Projects;

use App\Models\Projects\Issue;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TeamRemovedFromIssue
{
    use Dispatchable, SerializesModels;

    public function __construct(public Issue $issue, public Team $team, public ?User $actor = null) {}
}
