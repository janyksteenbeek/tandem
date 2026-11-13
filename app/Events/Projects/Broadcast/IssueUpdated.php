<?php

namespace App\Events\Projects\Broadcast;

use App\Http\Resources\Projects\IssueListItemResource;
use App\Models\Projects\Issue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to everyone looking at the issue, its project, product or one of its teams. Issues in
 * private projects stay on the issue and project channels only.
 */
class IssueUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public Issue $issue, public string $reason = 'updated') {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $this->issue->loadMissing(['project', 'product', 'workstreams']);

        $channels = [new PrivateChannel('issue.'.$this->issue->uuid)];

        if ($this->issue->project) {
            $channels[] = new PrivateChannel('project.'.$this->issue->project->uuid);
        }

        if ($this->issue->project?->isPrivate()) {
            return $channels;
        }

        $channels[] = new PrivateChannel('organization.'.$this->issue->organization_id.'.issues');

        if ($this->issue->product) {
            $channels[] = new PrivateChannel('product.'.$this->issue->product->uuid);
        }

        $teamIds = $this->issue->workstreams->pluck('team_id')->push($this->issue->lead_team_id)->filter()->unique();

        foreach ($teamIds as $teamId) {
            $channels[] = new PrivateChannel('team.'.$teamId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'issue.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'issue' => $this->reason === 'deleted' ? ['uuid' => $this->issue->uuid, 'identifier' => $this->issue->identifier] : IssueListItemResource::make($this->issue->fresh([
                'status', 'owner', 'leadTeam', 'labels', 'project', 'product', 'modules', 'parent', 'workstreams.team', 'workstreams.status', 'workstreams.assignees',
            ]) ?? $this->issue)->resolve(),
        ];
    }
}
