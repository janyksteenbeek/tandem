<?php

namespace App\Enums\Automations;

use App\Models\Mail\Inbox;
use App\Models\Tag;

enum AutomationConditionField: string
{
    case Inbox = 'inbox';
    case Sender = 'sender';
    case Recipient = 'recipient';
    case Subject = 'subject';
    case Body = 'body';
    case Tag = 'tag';
    case Assignee = 'assignee';
    case BusinessHours = 'business_hours';
    case Status = 'status';
    case Priority = 'priority';

    // Issues
    case IssueProduct = 'issue_product';
    case IssueProject = 'issue_project';
    case IssueTeam = 'issue_team';
    case IssueLeadTeam = 'issue_lead_team';
    case IssueStatus = 'issue_status';
    case IssueStatusCategory = 'issue_status_category';
    case IssuePriority = 'issue_priority';
    case IssueOwner = 'issue_owner';
    case IssueLabel = 'issue_label';
    case WorkstreamTeam = 'workstream_team';
    case WorkstreamStatusCategory = 'workstream_status_category';
    case IssueDaysUntilDue = 'issue_days_until_due';
    case IssueHasBlockers = 'issue_has_blockers';
    case IssueTitle = 'issue_title';

    public function label(): string
    {
        return match ($this) {
            self::Inbox => 'Inbox',
            self::Sender => 'Sender',
            self::Recipient => 'Recipient',
            self::Subject => 'Subject',
            self::Body => 'Body',
            self::Tag => 'Tag',
            self::Assignee => 'Assignee',
            self::BusinessHours => 'Business hours',
            self::Status => 'Status',
            self::Priority => 'Priority',
            self::IssueProduct => 'Product',
            self::IssueProject => 'Project',
            self::IssueTeam => 'Participating team',
            self::IssueLeadTeam => 'Lead team',
            self::IssueStatus => 'Overall status',
            self::IssueStatusCategory => 'Overall status category',
            self::IssuePriority => 'Priority',
            self::IssueOwner => 'Owner',
            self::IssueLabel => 'Label',
            self::WorkstreamTeam => 'Team that changed',
            self::WorkstreamStatusCategory => 'Team status category',
            self::IssueDaysUntilDue => 'Days until due',
            self::IssueHasBlockers => 'Has blockers',
            self::IssueTitle => 'Title',
        };
    }

    public function subjectType(): AutomationSubjectType
    {
        return match ($this) {
            self::IssueProduct, self::IssueProject, self::IssueTeam, self::IssueLeadTeam, self::IssueStatus, self::IssueStatusCategory,
            self::IssuePriority, self::IssueOwner, self::IssueLabel, self::WorkstreamTeam, self::WorkstreamStatusCategory,
            self::IssueDaysUntilDue, self::IssueHasBlockers, self::IssueTitle => AutomationSubjectType::Issue,
            default => AutomationSubjectType::Conversation,
        };
    }

    /**
     * The kind of value the UI should collect for this field.
     */
    public function valueType(): string
    {
        return match ($this) {
            self::Inbox => 'inbox',
            self::Sender, self::Recipient => 'email',
            self::Subject, self::Body => 'text',
            self::Tag => 'tag',
            self::Assignee => 'user',
            self::BusinessHours, self::IssueHasBlockers => 'boolean',
            self::Status => 'status',
            self::Priority => 'priority',
            self::IssueProduct => 'product',
            self::IssueProject => 'project',
            self::IssueTeam, self::IssueLeadTeam, self::WorkstreamTeam => 'team',
            self::IssueStatus => 'workflow_status',
            self::IssueStatusCategory, self::WorkstreamStatusCategory => 'status_category',
            self::IssuePriority => 'issue_priority',
            self::IssueOwner => 'user',
            self::IssueLabel => 'label',
            self::IssueDaysUntilDue => 'number',
            self::IssueTitle => 'text',
        };
    }

    /**
     * @return array<int, AutomationConditionOperator>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Inbox, self::Status, self::Priority => [
                AutomationConditionOperator::Equals,
                AutomationConditionOperator::NotEquals,
                AutomationConditionOperator::In,
                AutomationConditionOperator::NotIn,
            ],
            self::Sender, self::Recipient => [
                AutomationConditionOperator::Equals,
                AutomationConditionOperator::NotEquals,
                AutomationConditionOperator::Contains,
                AutomationConditionOperator::NotContains,
                AutomationConditionOperator::StartsWith,
                AutomationConditionOperator::EndsWith,
                AutomationConditionOperator::MatchesRegex,
                AutomationConditionOperator::In,
                AutomationConditionOperator::NotIn,
                AutomationConditionOperator::IsSet,
                AutomationConditionOperator::IsEmpty,
            ],
            self::Subject, self::Body => [
                AutomationConditionOperator::Equals,
                AutomationConditionOperator::NotEquals,
                AutomationConditionOperator::Contains,
                AutomationConditionOperator::NotContains,
                AutomationConditionOperator::StartsWith,
                AutomationConditionOperator::EndsWith,
                AutomationConditionOperator::MatchesRegex,
                AutomationConditionOperator::IsSet,
                AutomationConditionOperator::IsEmpty,
            ],
            self::Tag => [
                AutomationConditionOperator::Equals,
                AutomationConditionOperator::NotEquals,
                AutomationConditionOperator::Contains,
                AutomationConditionOperator::NotContains,
                AutomationConditionOperator::In,
                AutomationConditionOperator::NotIn,
                AutomationConditionOperator::IsSet,
                AutomationConditionOperator::IsEmpty,
            ],
            self::Assignee => [
                AutomationConditionOperator::Equals,
                AutomationConditionOperator::NotEquals,
                AutomationConditionOperator::In,
                AutomationConditionOperator::NotIn,
                AutomationConditionOperator::IsSet,
                AutomationConditionOperator::IsEmpty,
            ],
            self::BusinessHours, self::IssueHasBlockers => [
                AutomationConditionOperator::IsTrue,
                AutomationConditionOperator::IsFalse,
            ],
            self::IssueProduct, self::IssueProject, self::IssueTeam, self::IssueLeadTeam, self::IssueOwner, self::IssueLabel, self::WorkstreamTeam => [
                AutomationConditionOperator::Equals,
                AutomationConditionOperator::NotEquals,
                AutomationConditionOperator::In,
                AutomationConditionOperator::NotIn,
                AutomationConditionOperator::IsSet,
                AutomationConditionOperator::IsEmpty,
            ],
            self::IssueStatus, self::IssueStatusCategory, self::IssuePriority, self::WorkstreamStatusCategory => [
                AutomationConditionOperator::Equals,
                AutomationConditionOperator::NotEquals,
                AutomationConditionOperator::In,
                AutomationConditionOperator::NotIn,
            ],
            self::IssueDaysUntilDue => [
                AutomationConditionOperator::Equals,
                AutomationConditionOperator::AtMost,
                AutomationConditionOperator::AtLeast,
                AutomationConditionOperator::IsSet,
                AutomationConditionOperator::IsEmpty,
            ],
            self::IssueTitle => [
                AutomationConditionOperator::Equals,
                AutomationConditionOperator::NotEquals,
                AutomationConditionOperator::Contains,
                AutomationConditionOperator::NotContains,
                AutomationConditionOperator::StartsWith,
                AutomationConditionOperator::EndsWith,
                AutomationConditionOperator::MatchesRegex,
            ],
        };
    }

    public function supports(AutomationConditionOperator $operator): bool
    {
        return in_array($operator, $this->operators(), true);
    }

    /**
     * @return array<int, array{value: string, label: string, value_type: string, subject: string, operators: array<int, string>}>
     */
    public static function options(?AutomationSubjectType $subject = null): array
    {
        return collect(self::cases())
            ->filter(fn (self $field) => $subject === null || $field->subjectType() === $subject)
            ->map(fn (self $field) => [
                'value' => $field->value,
                'label' => $field->label(),
                'value_type' => $field->valueType(),
                'subject' => $field->subjectType()->value,
                'operators' => array_map(fn (AutomationConditionOperator $operator) => $operator->value, $field->operators()),
            ])
            ->values()
            ->all();
    }
}
