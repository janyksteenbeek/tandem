<?php

namespace App\Events\Projects\Broadcast;

use App\Http\Resources\Projects\IssueCommentResource;
use App\Models\Projects\IssueComment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IssueCommentAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public IssueComment $comment, public string $action = 'created') {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('issue.'.$this->comment->issue->uuid);
    }

    public function broadcastAs(): string
    {
        return 'comment.'.$this->action;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'comment' => $this->action === 'deleted'
                ? ['uuid' => $this->comment->uuid]
                : IssueCommentResource::make($this->comment->load(['user', 'reactions.user']))->resolve(),
        ];
    }
}
