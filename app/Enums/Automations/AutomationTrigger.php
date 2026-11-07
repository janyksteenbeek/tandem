<?php

namespace App\Enums\Automations;

use App\Events\Mail\ConversationAssigned;
use App\Events\Mail\ConversationMoved;

enum AutomationTrigger: string
{
    case ConversationReceived = 'conversation_received';
    case ReplyReceived = 'reply_received';
    case ConversationAssigned = 'conversation_assigned';
    case ConversationMoved = 'conversation_moved';
    case ConversationReopened = 'conversation_reopened';
    case ConversationClosed = 'conversation_closed';
    case TagAdded = 'tag_added';
    case BusinessHoursStarted = 'business_hours_started';
    case BusinessHoursEnded = 'business_hours_ended';

    // Issues
    case IssueCreated = 'issue_created';
    case IssueAssigned = 'issue_assigned';
    case TeamAdded = 'team_added';
    case WorkstreamStatusChanged = 'workstream_status_changed';
    case OverallStatusChanged = 'overall_status_changed';
    case IssueCompleted = 'issue_completed';
    case DependencyResolved = 'dependency_resolved';
    case DueDateApproaching = 'due_date_approaching';
    case IssueOverdue = 'issue_overdue';
    case CommentAdded = 'comment_added';
    case CycleStarted = 'cycle_started';
    case CycleCompleted = 'cycle_completed';

    public function label(): string
    {
        return match ($this) {
            self::ConversationReceived => 'New conversation received',
            self::ReplyReceived => 'New reply received',
            self::ConversationAssigned => 'Conversation assigned',
            self::ConversationMoved => 'Conversation moved',
            self::ConversationReopened => 'Conversation reopened',
            self::ConversationClosed => 'Conversation closed',
            self::TagAdded => 'Tag added',
            self::BusinessHoursStarted => 'Business hours started',
            self::BusinessHoursEnded => 'Business hours ended',
            self::IssueCreated => 'Issue created',
            self::IssueAssigned => 'Someone is assigned',
            self::TeamAdded => 'Team added to issue',
            self::WorkstreamStatusChanged => 'A team changes its status',
            self::OverallStatusChanged => 'Overall status changes',
            self::IssueCompleted => 'Issue completed',
            self::DependencyResolved => 'A blocker is resolved',
            self::DueDateApproaching => 'Due date approaching',
            self::IssueOverdue => 'Issue overdue',
            self::CommentAdded => 'Comment added',
            self::CycleStarted => 'Cycle started',
            self::CycleCompleted => 'Cycle completed',
        };
    }

    public function subjectType(): AutomationSubjectType
    {
        return match ($this) {
            self::IssueCreated, self::IssueAssigned, self::TeamAdded, self::WorkstreamStatusChanged, self::OverallStatusChanged,
            self::IssueCompleted, self::DependencyResolved, self::DueDateApproaching, self::IssueOverdue, self::CommentAdded,
            self::CycleStarted, self::CycleCompleted => AutomationSubjectType::Issue,
            default => AutomationSubjectType::Conversation,
        };
    }

    /**
     * Scheduled triggers are evaluated by a command instead of a domain event.
     */
    public function isScheduled(): bool
    {
        return in_array($this, [self::BusinessHoursStarted, self::BusinessHoursEnded, self::DueDateApproaching, self::IssueOverdue], true);
    }

    /**
     * @return array<array{value: string, label: string, subject: string}>
     */
    public static function options(?AutomationSubjectType $subject = null): array
    {
        return collect(self::cases())
            ->filter(fn (self $trigger) => $subject === null || $trigger->subjectType() === $subject)
            ->map(fn (self $trigger) => ['value' => $trigger->value, 'label' => $trigger->label(), 'subject' => $trigger->subjectType()->value])
            ->values()
            ->all();
    }
}
