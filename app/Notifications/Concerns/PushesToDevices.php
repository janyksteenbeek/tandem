<?php

namespace App\Notifications\Concerns;

use App\Enums\PushTopic;
use App\Models\Inbox;
use App\Models\User;
use App\Services\Branding;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Adds a device push to a notification that already describes itself through
 * toArray(): the same title and body, tapping opens the conversation.
 *
 * The channel is only included when the person has that topic switched on;
 * the channel itself skips people without a subscribed device.
 */
trait PushesToDevices
{
    /**
     * @return list<string>
     */
    protected function pushChannels(object $notifiable, PushTopic $topic): array
    {
        return $notifiable instanceof User && $notifiable->wantsPush($topic)
            ? [WebPushChannel::class]
            : [];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);
        $icons = app(Branding::class)->icons();

        $message = (new WebPushMessage)
            ->title((string) ($data['title'] ?? $data['message'] ?? app(Branding::class)->name()))
            ->icon($icons['icon_192'])
            ->badge($icons['icon_192'])
            ->data(['url' => $this->pushUrl($notifiable, $data)]);

        if (! empty($data['body'])) {
            $message->body((string) $data['body']);
        }

        if (! empty($data['conversation_uuid'])) {
            $message->tag('conversation-'.$data['conversation_uuid']);
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function pushUrl(object $notifiable, array $data): string
    {
        if (empty($data['inbox_uuid']) || empty($data['conversation_uuid'])) {
            return route('home');
        }

        $inbox = Inbox::query()->where('uuid', $data['inbox_uuid'])->with('organization')->first();
        $slug = $inbox?->organization?->slug
            ?? ($notifiable instanceof User ? $notifiable->currentOrganization?->slug : null);

        if ($slug === null) {
            return route('home');
        }

        return route('mail.inbox', [
            'current_organization' => $slug,
            'inbox' => $data['inbox_uuid'],
            'view' => 'all',
            'conversation' => $data['conversation_uuid'],
        ]);
    }
}
