<?php

namespace App\Notifications\Projects;

use App\Enums\PushTopic;
use App\Models\Projects\Issue;
use App\Models\Projects\WorkflowStatus;
use App\Models\User;

class IssueStatusChangedNotification extends IssueNotification
{
    public function __construct(Issue $issue, public WorkflowStatus $from, public WorkflowStatus $to, ?User $actor = null)
    {
        parent::__construct($issue, $actor);
    }

    protected function kind(): string
    {
        return 'issue_status';
    }

    protected function title(): string
    {
        return $this->issue->identifier.' moved to '.$this->to->name;
    }

    protected function body(): ?string
    {
        return $this->issue->title.' · by '.$this->actorName();
    }

    protected function topic(): PushTopic
    {
        return PushTopic::Follows;
    }

    protected function preferenceKey(): ?string
    {
        return 'status_changes';
    }
}
