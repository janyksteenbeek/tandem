<?php

namespace App\Notifications\Projects;

use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;

class WorkstreamHandoffNotification extends IssueNotification
{
    public function __construct(Issue $issue, public IssueWorkstream $workstream, public IssueWorkstream $from)
    {
        parent::__construct($issue);
    }

    protected function kind(): string
    {
        return 'handoff';
    }

    protected function title(): string
    {
        return $this->from->team->name.' handed '.$this->issue->identifier.' over to '.$this->workstream->team->name;
    }
}
