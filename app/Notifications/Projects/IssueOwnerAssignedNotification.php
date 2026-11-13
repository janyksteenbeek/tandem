<?php

namespace App\Notifications\Projects;

class IssueOwnerAssignedNotification extends IssueNotification
{
    protected function kind(): string
    {
        return 'issue_owner';
    }

    protected function title(): string
    {
        return $this->actorName().' made you the owner of '.$this->issue->identifier;
    }
}
