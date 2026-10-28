<?php

namespace App\Notifications\Conversations;

use App\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewCommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Comment $comment)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'comment',
            'title' => $this->comment->user->name.' commented on a conversation you follow',
            'body' => Str::limit(strip_tags($this->comment->body), 140),
            'conversation_uuid' => $this->comment->conversation->uuid,
            'inbox_uuid' => $this->comment->conversation->inbox->uuid,
            'comment_uuid' => $this->comment->uuid,
            'actor' => ['id' => $this->comment->user->id, 'name' => $this->comment->user->name],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onQueue('broadcasts');
    }
}
