<?php

namespace App\Notifications\Projects;

use App\Enums\PushTopic;

class IssueCreatedFromConversationNotification extends IssueNotification
{
    protected function kind(): string
    {
        return 'issue_from_email';
    }

    protected function title(): string
    {
        return 'An email created '.$this->issue->identifier;
    }

    protected function topic(): PushTopic
    {
        return PushTopic::Teams;
    }
}
