<?php

namespace App\Notifications\Mail;

use App\Enums\PushTopic;
use App\Models\Message;
use App\Notifications\Concerns\PushesToDevices;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A device push, and nothing else, when a new conversation lands in a shared
 * inbox. It never reaches the bell: the inbox list already shows it, and a
 * busy support@ would bury everything personal.
 */
class NewSharedMailNotification extends Notification
{
    use PushesToDevices;
    use Queueable;

    public function __construct(public Message $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->pushChannels($notifiable, PushTopic::Shared);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $conversation = $this->message->conversation;
        $from = $this->message->fromName() ?: $this->message->fromAddress();

        return [
            'kind' => 'new_mail',
            'title' => 'New in '.$conversation->inbox->name,
            'body' => Str::limit(trim($from.' · '.($conversation->subject ?: '(no subject)'), ' ·'), 140),
            'conversation_uuid' => $conversation->uuid,
            'inbox_uuid' => $conversation->inbox->uuid,
        ];
    }
}
