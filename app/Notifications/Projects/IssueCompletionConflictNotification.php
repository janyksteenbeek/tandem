<?php

namespace App\Notifications\Projects;

class IssueCompletionConflictNotification extends IssueNotification
{
    protected function kind(): string
    {
        return 'issue_conflict';
    }

    protected function title(): string
    {
        return $this->issue->identifier.' is marked done, but a team reopened its work';
    }
}
