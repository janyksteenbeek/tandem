<?php

namespace App\Events\Projects\Broadcast;

use App\Http\Resources\Projects\WorkstreamResource;
use App\Models\Projects\IssueWorkstream;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkstreamUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public IssueWorkstream $workstream, public string $reason = 'updated') {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $this->workstream->loadMissing('issue');

        return [
            new PrivateChannel('issue.'.$this->workstream->issue->uuid),
            new PrivateChannel('team.'.$this->workstream->team_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'workstream.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'issue_uuid' => $this->workstream->issue->uuid,
            'workstream' => $this->reason === 'deleted'
                ? ['uuid' => $this->workstream->uuid, 'team_id' => $this->workstream->team_id]
                : WorkstreamResource::make($this->workstream->load(['team', 'status', 'assignees', 'cycle']))->resolve(),
        ];
    }
}
