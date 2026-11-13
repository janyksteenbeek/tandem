<?php

namespace App\Notifications\Projects;

use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;
use App\Models\User;

class WorkstreamAssignedNotification extends IssueNotification
{
    public function __construct(Issue $issue, public IssueWorkstream $workstream, ?User $actor = null)
    {
        parent::__construct($issue, $actor);
    }

    protected function kind(): string
    {
        return 'issue_assigned';
    }

    protected function title(): string
    {
        return $this->actorName().' assigned you '.$this->issue->identifier.' for '.$this->workstream->team->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return parent::toArray($notifiable) + ['workstream_uuid' => $this->workstream->uuid, 'team' => $this->workstream->team->name];
    }
}
