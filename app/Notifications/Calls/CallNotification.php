<?php

namespace App\Notifications\Calls;

use App\Enums\PushTopic;
use App\Models\Calls\Call;
use App\Notifications\Concerns\PushesToDevices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for call notifications: database + broadcast, plus a device push for people who have
 * direct notifications switched on. `url` is where a click or tap should land.
 */
abstract class CallNotification extends Notification implements ShouldQueue
{
    use PushesToDevices;
    use Queueable;

    public function __construct(public Call $call)
    {
        $this->onQueue('notifications');
    }

    abstract protected function kind(): string;

    abstract protected function title(): string;

    abstract protected function url(): string;

    protected function body(): ?string
    {
        return $this->call->displayTitle();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [...['database', 'broadcast'], ...$this->pushChannels($notifiable, PushTopic::Direct)];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind(),
            'title' => $this->title(),
            'body' => $this->body(),
            'call_uuid' => $this->call->uuid,
            'call_code' => $this->call->code,
            'url' => $this->url(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onQueue('broadcasts');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function pushUrl(object $notifiable, array $data): string
    {
        return (string) ($data['url'] ?? route('home'));
    }
}
