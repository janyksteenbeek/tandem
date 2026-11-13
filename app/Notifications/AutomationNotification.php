<?php

namespace App\Notifications;

use App\Enums\PushTopic;
use App\Models\Automations\AutomationRule;
use App\Models\Mail\Conversation;
use App\Models\Projects\Issue;
use App\Notifications\Concerns\PushesToDevices;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Database notification raised by the `notify_users` automation action.
 */
class AutomationNotification extends Notification
{
    use PushesToDevices;
    use Queueable;

    public function __construct(
        public AutomationRule $rule,
        public Conversation|Issue $subject,
        public ?string $message = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [...['database'], ...$this->pushChannels($notifiable, PushTopic::Direct)];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $base = [
            'kind' => 'automation',
            'rule_id' => $this->rule->id,
            'rule_uuid' => $this->rule->uuid,
            'rule_name' => $this->rule->name,
        ];

        if ($this->subject instanceof Issue) {
            return $base + [
                'issue_uuid' => $this->subject->uuid,
                'issue_identifier' => $this->subject->identifier,
                'issue_title' => $this->subject->title,
                'title' => $this->message ?: sprintf('Automation "%s" flagged %s for you.', $this->rule->name, $this->subject->identifier),
                'body' => $this->subject->title,
                'message' => $this->message ?: sprintf('Automation "%s" flagged %s for you.', $this->rule->name, $this->subject->identifier),
            ];
        }

        return $base + [
            'conversation_id' => $this->subject->id,
            'conversation_uuid' => $this->subject->uuid,
            'subject' => $this->subject->subject,
            'inbox_uuid' => $this->subject->inbox->uuid,
            'message' => $this->message ?: sprintf('Automation "%s" flagged a conversation for you.', $this->rule->name),
        ];
    }
}
