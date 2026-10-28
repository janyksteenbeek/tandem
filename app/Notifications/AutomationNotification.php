<?php

namespace App\Notifications;

use App\Models\AutomationRule;
use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Database notification raised by the `notify_users` automation action.
 */
class AutomationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public AutomationRule $rule,
        public Conversation $conversation,
        public ?string $message = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'automation',
            'rule_id' => $this->rule->id,
            'rule_uuid' => $this->rule->uuid,
            'rule_name' => $this->rule->name,
            'conversation_id' => $this->conversation->id,
            'conversation_uuid' => $this->conversation->uuid,
            'subject' => $this->conversation->subject,
            'inbox_uuid' => $this->conversation->inbox->uuid,
            'message' => $this->message ?: sprintf('Automation "%s" flagged a conversation for you.', $this->rule->name),
        ];
    }
}
