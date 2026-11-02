<?php

namespace App\Events\Broadcast;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class MessageAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public Message $message) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->conversation->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'message.added';
    }

    /**
     * Only identifiers go over the socket: the thread view re-fetches the
     * conversation on this event, and a full message (HTML body, attachments)
     * easily exceeds Reverb's payload limit.
     *
     * @return array{message: array{uuid: string, conversation_uuid: string, direction: string, snippet: string|null, sent_at: string|null}}
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'uuid' => $this->message->uuid,
                'conversation_uuid' => $this->message->conversation->uuid,
                'direction' => $this->message->direction->value,
                'snippet' => $this->message->snippet ? Str::limit($this->message->snippet, 140) : null,
                'sent_at' => $this->message->sent_at?->toISOString(),
            ],
        ];
    }
}
