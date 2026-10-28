<?php

namespace App\Listeners\Mail;

use App\Events\Broadcast\ConversationUpdated;
use App\Events\Broadcast\MessageDeliveryUpdated;
use App\Events\Mail\MessageSent;

class BroadcastSentMessage
{
    public function handle(MessageSent $event): void
    {
        MessageDeliveryUpdated::dispatch($event->message);
        ConversationUpdated::dispatch($event->message->conversation->fresh(), 'message');
    }
}
