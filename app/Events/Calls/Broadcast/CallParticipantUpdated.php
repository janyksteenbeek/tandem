<?php

namespace App\Events\Calls\Broadcast;

use App\Http\Resources\Calls\CallParticipantResource;
use App\Models\Calls\CallParticipant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone asked to join, was let in, connected, left or was removed.
 */
class CallParticipantUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public CallParticipant $participant) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('call.'.$this->participant->call->uuid);
    }

    public function broadcastAs(): string
    {
        return 'participant.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['participant' => CallParticipantResource::make($this->participant->loadMissing('user'))->resolve()];
    }
}
