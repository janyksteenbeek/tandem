<?php

namespace App\Notifications\Projects;

use App\Enums\PushTopic;
use App\Models\Projects\Issue;
use App\Models\Team;
use App\Models\User;

class TeamAddedToIssueNotification extends IssueNotification
{
    public function __construct(Issue $issue, public Team $team, ?User $actor = null)
    {
        parent::__construct($issue, $actor);
    }

    protected function kind(): string
    {
        return 'team_added';
    }

    protected function title(): string
    {
        return $this->actorName().' added '.$this->team->name.' to '.$this->issue->identifier;
    }

    protected function topic(): PushTopic
    {
        return PushTopic::Teams;
    }
}
