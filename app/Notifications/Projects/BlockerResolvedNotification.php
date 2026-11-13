<?php

namespace App\Notifications\Projects;

use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;

class BlockerResolvedNotification extends IssueNotification
{
    public function __construct(Issue $issue, public ?IssueWorkstream $workstream, public string $resolvedBy)
    {
        parent::__construct($issue);
    }

    protected function kind(): string
    {
        return 'blocker_resolved';
    }

    protected function title(): string
    {
        $target = $this->workstream ? $this->issue->identifier.' ('.$this->workstream->team->name.')' : $this->issue->identifier;

        return $target.' is unblocked: '.$this->resolvedBy.' is done';
    }
}
