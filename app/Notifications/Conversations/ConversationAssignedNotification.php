<?php

namespace App\Notifications\Conversations;

use App\Enums\PushTopic;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\Concerns\PushesToDevices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ConversationAssignedNotification extends Notification implements ShouldQueue
{
    use PushesToDevices;
    use Queueable;

    public function __construct(public Conversation $conversation, public ?User $actor)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [...['database', 'broadcast'], ...$this->pushChannels($notifiable, PushTopic::Direct)];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'assigned',
            'title' => ($this->actor->name ?? 'Someone').' assigned you a conversation',
            'body' => $this->conversation->subject,
            'conversation_uuid' => $this->conversation->uuid,
            'inbox_uuid' => $this->conversation->inbox->uuid,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onQueue('broadcasts');
    }
}
