<?php

namespace App\Events\Calls\Broadcast;

use App\Http\Resources\Calls\CallMessageResource;
use App\Models\Calls\CallMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chat message was posted, edited, reacted to or deleted. Carries ids rather than the model
 * so a deleted (soft-deleted) message can still be announced from the queue.
 */
class CallMessageChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public int $messageId;

    public string $callUuid;

    public function __construct(CallMessage $message, public string $action = 'created')
    {
        $this->messageId = $message->id;
        $this->callUuid = $message->call->uuid;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('call.'.$this->callUuid);
    }

    public function broadcastAs(): string
    {
        return 'message.'.$this->action;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = CallMessage::withTrashed()->findOrFail($this->messageId);

        return ['message' => CallMessageResource::make($message)->resolve()];
    }
}
