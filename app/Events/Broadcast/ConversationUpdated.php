<?php

namespace App\Events\Broadcast;

use App\Http\Resources\ConversationListItemResource;
use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to every inbox that can see the conversation whenever its list-level state changes.
 */
class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'broadcasts';

    /**
     * @param  array<int, int>  $removedFromInboxIds
     */
    public function __construct(
        public Conversation $conversation,
        public string $reason = 'updated',
        public array $removedFromInboxIds = [],
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $this->conversation->loadMissing('shares');

        $inboxIds = collect([$this->conversation->inbox_id])
            ->merge($this->conversation->shares->pluck('inbox_id'))
            ->merge($this->removedFromInboxIds)
            ->unique();

        return $inboxIds
            ->map(fn (int $id) => new PrivateChannel('inbox.'.$id))
            ->push(new PrivateChannel('conversation.'.$this->conversation->uuid))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $conversation = $this->conversation->fresh(['inbox', 'assignee', 'tags', 'shares']);

        return [
            'reason' => $this->reason,
            'removed_from_inbox_ids' => $this->removedFromInboxIds,
            'conversation' => $conversation ? ConversationListItemResource::make($conversation)->resolve() : null,
        ];
    }
}
