<?php

namespace App\Enums;

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
        };
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $trigger) => ['value' => $trigger->value, 'label' => $trigger->label()])
            ->all();
    }
}
