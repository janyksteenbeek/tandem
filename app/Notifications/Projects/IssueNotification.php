<?php

namespace App\Notifications\Projects;

use App\Enums\PushTopic;
use App\Models\Projects\Issue;
use App\Models\User;
use App\Notifications\Concerns\PushesToDevices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for every issue notification: database + broadcast, plus a device push when the person
 * has the topic switched on. Subclasses provide kind, title, body and topic.
 */
abstract class IssueNotification extends Notification implements ShouldQueue
{
    use PushesToDevices;
    use Queueable;

    public function __construct(public Issue $issue, public ?User $actor = null)
    {
        $this->onQueue('notifications');
    }

    abstract protected function kind(): string;

    abstract protected function title(): string;

    protected function body(): ?string
    {
        return $this->issue->identifier.' · '.$this->issue->title;
    }

    protected function topic(): PushTopic
    {
        return PushTopic::Direct;
    }

    /**
     * Preference key under `notifications.issues.*` that can silence this notification.
     */
    protected function preferenceKey(): ?string
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User && $this->preferenceKey() && $notifiable->preference('notifications.issues.'.$this->preferenceKey(), true) === false) {
            return [];
        }

        return [...['database', 'broadcast'], ...$this->pushChannels($notifiable, $this->topic())];
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
            'issue_uuid' => $this->issue->uuid,
            'issue_identifier' => $this->issue->identifier,
            'issue_title' => $this->issue->title,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onQueue('broadcasts');
    }

    protected function actorName(): string
    {
        return $this->actor->name ?? 'Someone';
    }
}
