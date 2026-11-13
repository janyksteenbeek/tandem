<?php

namespace App\Notifications\Projects;

use App\Models\Projects\Issue;

class IssueDueNotification extends IssueNotification
{
    public function __construct(Issue $issue, public bool $overdue)
    {
        parent::__construct($issue);
    }

    protected function kind(): string
    {
        return $this->overdue ? 'issue_overdue' : 'issue_due_soon';
    }

    protected function title(): string
    {
        return $this->overdue
            ? $this->issue->identifier.' is overdue'
            : $this->issue->identifier.' is due '.$this->issue->due_date?->diffForHumans();
    }

    protected function preferenceKey(): ?string
    {
        return 'due_dates';
    }
}
