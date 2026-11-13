<?php

namespace App\Events\Calls\Broadcast;

use App\Http\Resources\Calls\CallRecordingResource;
use App\Http\Resources\Calls\CallResource;
use App\Models\Calls\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The call itself changed: settings, lock, status, or the state of its recording. `reason`
 * tells the browser what to say about it (`recording.started`, `ended`, ...).
 */
class CallUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'broadcasts';

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(public Call $call, public string $reason = 'updated', public array $extra = []) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('call.'.$this->call->uuid);
    }

    public function broadcastAs(): string
    {
        return 'call.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $call = $this->call->fresh(['organization', 'host']) ?? $this->call;
        $recording = $call->recordings()->with('startedBy')->latest('id')->first();

        return [
            'reason' => $this->reason,
            'call' => CallResource::make($call)->resolve(),
            'recording' => $recording ? CallRecordingResource::make($recording)->resolve() : null,
            ...$this->extra,
        ];
    }
}
