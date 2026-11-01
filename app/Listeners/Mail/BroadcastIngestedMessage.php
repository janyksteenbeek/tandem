<?php

namespace App\Listeners\Mail;

use App\Events\Broadcast\ConversationUpdated;
use App\Events\Broadcast\MessageAdded;
use App\Events\Broadcast\UserNotified;
use App\Events\Mail\MessageIngested;
use App\Models\User;
use App\Notifications\Conversations\NewReplyNotification;

/**
 * Fan an ingested message out to the UI: list updates for every inbox that sees the thread,
 * the message itself for anyone with the thread open, and a nudge for sidebar counters.
 */
class BroadcastIngestedMessage
{
    public function handle(MessageIngested $event): void
    {
        $conversation = $event->conversation->fresh(['inbox', 'shares', 'assignee', 'followers']);

        if (! $conversation) {
            return;
        }

        ConversationUpdated::dispatch($conversation, $event->isNewConversation ? 'created' : 'message');
        MessageAdded::dispatch($event->message);

        $recipients = collect();

        if ($conversation->inbox->isPersonal() && $conversation->inbox->user_id) {
            $recipients->push($conversation->inbox->user_id);
        }

        $recipients = $recipients
            ->merge($conversation->assignee ? [$conversation->assignee->id] : [])
            ->merge($conversation->followers->pluck('id'))
            ->unique();

        foreach ($recipients as $userId) {
            UserNotified::dispatch($userId, 'inbox', [
                'inbox_id' => $conversation->inbox_id,
                'conversation_uuid' => $conversation->uuid,
                'inbound' => $event->message->isInbound(),
            ]);
        }

        if ($event->message->isInbound() && ! $event->isNewConversation) {
            $conversation->followers
                ->merge($conversation->assignee ? [$conversation->assignee] : [])
                ->unique('id')
                ->each(fn (User $user) => $user->notify(new NewReplyNotification($event->message)));
        }
    }
}
