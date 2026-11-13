<?php

namespace App\Enums\Automations;

use App\Models\Mail\Conversation;
use App\Models\Projects\Issue;

/**
 * What a rule reacts to: a mail conversation or a project issue.
 */
enum AutomationSubjectType: string
{
    case Conversation = 'conversation';
    case Issue = 'issue';

    public function label(): string
    {
        return match ($this) {
            self::Conversation => 'Conversation',
            self::Issue => 'Issue',
        };
    }

    /**
     * @return class-string<Conversation|Issue>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Conversation => Conversation::class,
            self::Issue => Issue::class,
        };
    }

    public static function of(object $subject): self
    {
        return $subject instanceof Issue ? self::Issue : self::Conversation;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
