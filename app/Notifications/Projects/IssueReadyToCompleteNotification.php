<?php

namespace App\Notifications\Projects;

class IssueReadyToCompleteNotification extends IssueNotification
{
    protected function kind(): string
    {
        return 'issue_ready';
    }

    protected function title(): string
    {
        return 'Every team finished '.$this->issue->identifier.' — ready to complete';
    }
}
