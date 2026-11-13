<?php

namespace App\Events\Projects\Broadcast;

use App\Models\Projects\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProjectUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public Project $project, public string $reason = 'updated') {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('project.'.$this->project->uuid)];

        if ($this->project->product_id && ! $this->project->isPrivate()) {
            $this->project->loadMissing('product');
            $channels[] = new PrivateChannel('product.'.$this->project->product->uuid);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'project.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason, 'uuid' => $this->project->uuid, 'progress' => $this->project->progress];
    }
}
