<?php

namespace App\Enums;

enum AutomationConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case MatchesRegex = 'matches_regex';
    case In = 'in';
    case NotIn = 'not_in';
    case IsSet = 'is_set';
    case IsEmpty = 'is_empty';
    case IsTrue = 'is_true';
    case IsFalse = 'is_false';

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'is',
            self::NotEquals => 'is not',
            self::Contains => 'contains',
            self::NotContains => 'does not contain',
            self::StartsWith => 'starts with',
            self::EndsWith => 'ends with',
            self::MatchesRegex => 'matches regex',
            self::In => 'is any of',
            self::NotIn => 'is none of',
            self::IsSet => 'is set',
            self::IsEmpty => 'is empty',
            self::IsTrue => 'is true',
            self::IsFalse => 'is false',
        };
    }

    /**
     * The shape of the condition "value" this operator expects: "string", "array" or "none".
     */
    public function valueShape(): string
    {
        return match ($this) {
            self::In, self::NotIn => 'array',
            self::IsSet, self::IsEmpty, self::IsTrue, self::IsFalse => 'none',
            default => 'string',
        };
    }

    public function isNegated(): bool
    {
        return in_array($this, [self::NotEquals, self::NotContains, self::NotIn], true);
    }

    /**
     * @return array<int, array{value: string, label: string, value_shape: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $operator) => [
                'value' => $operator->value,
                'label' => $operator->label(),
                'value_shape' => $operator->valueShape(),
            ])
            ->all();
    }
}
