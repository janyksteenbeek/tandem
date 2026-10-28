<?php

namespace App\Enums;

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
            self::BusinessHours => 'boolean',
            self::Status => 'status',
            self::Priority => 'priority',
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
            self::BusinessHours => [
                AutomationConditionOperator::IsTrue,
                AutomationConditionOperator::IsFalse,
            ],
        };
    }

    public function supports(AutomationConditionOperator $operator): bool
    {
        return in_array($operator, $this->operators(), true);
    }

    /**
     * @return array<int, array{value: string, label: string, value_type: string, operators: array<int, string>}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $field) => [
                'value' => $field->value,
                'label' => $field->label(),
                'value_type' => $field->valueType(),
                'operators' => array_map(fn (AutomationConditionOperator $operator) => $operator->value, $field->operators()),
            ])
            ->all();
    }
}
