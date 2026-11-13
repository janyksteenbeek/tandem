<?php

namespace App\Events\Projects\Broadcast;

use App\Http\Resources\Projects\IssueActivityResource;
use App\Models\Projects\IssueActivity;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IssueActivityAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public IssueActivity $activity) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('issue.'.$this->activity->issue->uuid);
    }

    public function broadcastAs(): string
    {
        return 'activity.added';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['activity' => IssueActivityResource::make($this->activity->load(['actor', 'workstream.team']))->resolve()];
    }
}
