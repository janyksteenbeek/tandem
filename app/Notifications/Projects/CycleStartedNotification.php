<?php

namespace App\Notifications\Projects;

use App\Enums\PushTopic;
use App\Models\Projects\Cycle;
use App\Notifications\Concerns\PushesToDevices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class CycleStartedNotification extends Notification implements ShouldQueue
{
    use PushesToDevices;
    use Queueable;

    public function __construct(public Cycle $cycle)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [...['database', 'broadcast'], ...$this->pushChannels($notifiable, PushTopic::Teams)];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'cycle_started',
            'title' => $this->cycle->team->name.': '.$this->cycle->displayName().' started',
            'body' => $this->cycle->workstreams_count.' items planned, ends '.$this->cycle->ends_at->toFormattedDateString(),
            'cycle_uuid' => $this->cycle->uuid,
            'team_slug' => $this->cycle->team->slug,
            'actor' => null,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onQueue('broadcasts');
    }
}
