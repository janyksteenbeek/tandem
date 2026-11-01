<?php

namespace App\Notifications\Conversations;

use App\Enums\PushTopic;
use App\Models\Message;
use App\Notifications\Concerns\PushesToDevices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class NewReplyNotification extends Notification implements ShouldQueue
{
    use PushesToDevices;
    use Queueable;

    public function __construct(public Message $message)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [...['database', 'broadcast'], ...$this->pushChannels($notifiable, PushTopic::Follows)];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'reply',
            'title' => ($this->message->fromName() ?: $this->message->fromAddress()).' replied',
            'body' => $this->message->conversation->subject,
            'conversation_uuid' => $this->message->conversation->uuid,
            'inbox_uuid' => $this->message->conversation->inbox->uuid,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onQueue('broadcasts');
    }
}
