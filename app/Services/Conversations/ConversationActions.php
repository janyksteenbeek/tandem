<?php

namespace App\Services\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Events\Broadcast\ActivityAdded;
use App\Events\Broadcast\ConversationUpdated;
use App\Events\Conversations\ConversationAssigned;
use App\Events\Conversations\ConversationStatusChanged;
use App\Events\Conversations\ConversationTagged;
use App\Jobs\Mail\PushMailboxChange;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\Conversations\ConversationAssignedNotification;
use App\Services\Mail\Ingestion\MessageIngestor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Every state change a person can make to a conversation goes through here so the activity
 * timeline, realtime broadcast, automations and provider write-back always stay in step.
 */
class ConversationActions
{
    public function __construct(protected MessageIngestor $ingestor) {}

    public function assign(Conversation $conversation, ?User $assignee, ?User $actor): Conversation
    {
        $previous = $conversation->assignee;

        if ($previous?->id === $assignee?->id) {
            return $conversation;
        }

        DB::transaction(function () use ($conversation, $assignee, $actor, $previous) {
            $conversation->forceFill([
                'assignee_id' => $assignee?->id,
                'assigned_by_id' => $assignee ? $actor?->id : null,
                'assigned_at' => $assignee ? now() : null,
                'last_activity_at' => now(),
            ])->save();

            $this->record($conversation, $assignee ? ConversationEventType::Assigned : ConversationEventType::Unassigned, $actor, [
                'assignee' => $assignee ? ['id' => $assignee->id, 'name' => $assignee->name] : null,
                'previous' => $previous ? ['id' => $previous->id, 'name' => $previous->name] : null,
            ]);

            if ($assignee) {
                $conversation->followers()->syncWithoutDetaching([$assignee->id]);
            }
        });

        ConversationAssigned::dispatch($conversation, $previous, $assignee, $actor);

        if ($assignee && $assignee->id !== $actor?->id) {
            $assignee->notify(new ConversationAssignedNotification($conversation, $actor));
        }

        $this->broadcast($conversation, 'assigned');

        return $conversation;
    }

    public function setStatus(Conversation $conversation, ConversationStatus $status, ?User $actor, ?CarbonInterface $snoozedUntil = null): Conversation
    {
        $from = $conversation->status;

        if ($from === $status && $status !== ConversationStatus::Snoozed) {
            return $conversation;
        }

        DB::transaction(function () use ($conversation, $status, $actor, $from, $snoozedUntil) {
            $conversation->forceFill([
                'status' => $status,
                'snoozed_until' => $status === ConversationStatus::Snoozed ? $snoozedUntil : null,
                'snoozed_by_id' => $status === ConversationStatus::Snoozed ? $actor?->id : null,
                'closed_at' => $status === ConversationStatus::Closed ? now() : null,
                'closed_by_id' => $status === ConversationStatus::Closed ? $actor?->id : null,
                'last_activity_at' => now(),
            ])->save();

            $type = match ($status) {
                ConversationStatus::Snoozed => ConversationEventType::Snoozed,
                default => $from === ConversationStatus::Snoozed ? ConversationEventType::Unsnoozed : ConversationEventType::StatusChanged,
            };

            $this->record($conversation, $type, $actor, [
                'from' => $from->value,
                'to' => $status->value,
                'until' => $snoozedUntil?->toISOString(),
            ]);
        });

        ConversationStatusChanged::dispatch($conversation, $from, $status, $actor);
        $this->broadcast($conversation, 'status');

        return $conversation;
    }

    public function snooze(Conversation $conversation, CarbonInterface $until, ?User $actor): Conversation
    {
        return $this->setStatus($conversation, ConversationStatus::Snoozed, $actor, $until);
    }

    public function wake(Conversation $conversation): Conversation
    {
        $conversation = $this->setStatus($conversation, ConversationStatus::Open, null);

        if (! $conversation->in_inbox && ! $conversation->is_trashed && ! $conversation->is_spam) {
            $conversation->forceFill(['in_inbox' => true, 'is_archived' => false])->save();
        }

        return $conversation;
    }

    public function setPriority(Conversation $conversation, ConversationPriority $priority, ?User $actor): Conversation
    {
        $from = $conversation->priority;

        if ($from === $priority) {
            return $conversation;
        }

        $conversation->forceFill(['priority' => $priority, 'last_activity_at' => now()])->save();
        $this->record($conversation, ConversationEventType::PriorityChanged, $actor, ['from' => $from->key(), 'to' => $priority->key()]);
        $this->broadcast($conversation, 'priority');

        return $conversation;
    }

    public function addTag(Conversation $conversation, Tag $tag, ?User $actor): Conversation
    {
        if ($conversation->tags()->whereKey($tag->id)->exists()) {
            return $conversation;
        }

        $conversation->tags()->attach($tag->id);
        $conversation->forceFill(['last_activity_at' => now()])->save();
        $this->record($conversation, ConversationEventType::TagAdded, $actor, ['tag' => ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color]]);

        ConversationTagged::dispatch($conversation, $tag, $actor);
        $this->broadcast($conversation, 'tags');

        return $conversation;
    }

    public function removeTag(Conversation $conversation, Tag $tag, ?User $actor): Conversation
    {
        if ($conversation->tags()->detach($tag->id) === 0) {
            return $conversation;
        }

        $this->record($conversation, ConversationEventType::TagRemoved, $actor, ['tag' => ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color]]);
        $this->broadcast($conversation, 'tags');

        return $conversation;
    }

    public function setStarred(Conversation $conversation, bool $starred, ?User $actor): Conversation
    {
        if ($conversation->is_starred === $starred) {
            return $conversation;
        }

        $conversation->forceFill(['is_starred' => $starred])->save();

        $latest = $conversation->messages()->where('is_draft', false)->latest('sent_at')->first();

        if ($latest) {
            $latest->forceFill(['is_starred' => $starred, 'mailbox_state' => array_merge($latest->mailbox_state ?? [], ['isStarred' => $starred])])->save();
            $this->pushToProvider($conversation, [$latest->id], $starred ? 'star' : 'unstar');
        }

        $this->broadcast($conversation, 'starred');

        return $conversation;
    }

    public function markRead(Conversation $conversation, bool $read, ?User $actor): Conversation
    {
        $query = $conversation->messages()->where('is_draft', false)->where('is_read', ! $read);
        $ids = $read ? $query->pluck('id')->all() : [$conversation->messages()->where('is_draft', false)->latest('sent_at')->value('id')];
        $ids = array_values(array_filter($ids));

        if ($ids === [] && $conversation->is_read === $read) {
            return $conversation;
        }

        Message::query()->whereKey($ids)->get()->each(function (Message $message) use ($read) {
            $message->forceFill(['is_read' => $read, 'mailbox_state' => array_merge($message->mailbox_state ?? [], ['isRead' => $read])])->save();
        });

        $conversation->refreshCounters();

        if ($ids !== []) {
            $this->pushToProvider($conversation, $ids, $read ? 'read' : 'unread');
        }

        $this->broadcast($conversation, 'read');

        return $conversation;
    }

    public function archive(Conversation $conversation, ?User $actor): Conversation
    {
        return $this->placeMailbox($conversation, $actor, 'archive', ['inInbox' => false, 'isArchived' => true, 'isTrashed' => false, 'isSpam' => false], ConversationEventType::Archived);
    }

    public function moveToInbox(Conversation $conversation, ?User $actor): Conversation
    {
        return $this->placeMailbox($conversation, $actor, 'inbox', ['inInbox' => true, 'isArchived' => false, 'isTrashed' => false, 'isSpam' => false], ConversationEventType::Restored);
    }

    public function trash(Conversation $conversation, ?User $actor): Conversation
    {
        return $this->placeMailbox($conversation, $actor, 'trash', ['inInbox' => false, 'isArchived' => false, 'isTrashed' => true, 'isSpam' => false], ConversationEventType::Trashed);
    }

    public function restore(Conversation $conversation, ?User $actor): Conversation
    {
        return $this->placeMailbox($conversation, $actor, 'restore', ['inInbox' => true, 'isArchived' => false, 'isTrashed' => false, 'isSpam' => false], ConversationEventType::Restored);
    }

    public function markSpam(Conversation $conversation, bool $spam, ?User $actor): Conversation
    {
        return $this->placeMailbox(
            $conversation,
            $actor,
            $spam ? 'spam' : 'not_spam',
            ['inInbox' => ! $spam, 'isArchived' => false, 'isTrashed' => false, 'isSpam' => $spam],
            $spam ? ConversationEventType::Trashed : ConversationEventType::Restored,
        );
    }

    public function follow(Conversation $conversation, User $user, bool $follow, ?User $actor = null): Conversation
    {
        if ($follow) {
            $conversation->followers()->syncWithoutDetaching([$user->id]);
            $this->record($conversation, ConversationEventType::FollowerAdded, $actor ?? $user, ['user' => ['id' => $user->id, 'name' => $user->name]]);
        } else {
            $conversation->followers()->detach($user->id);
            $this->record($conversation, ConversationEventType::FollowerRemoved, $actor ?? $user, ['user' => ['id' => $user->id, 'name' => $user->name]]);
        }

        return $conversation;
    }

    public function delete(Conversation $conversation, ?User $actor): void
    {
        $conversation->delete();
        ConversationUpdated::dispatch($conversation, 'deleted', [$conversation->inbox_id]);
    }

    /**
     * @param  array<string, bool>  $state
     */
    protected function placeMailbox(Conversation $conversation, ?User $actor, string $operation, array $state, ConversationEventType $eventType): Conversation
    {
        $messages = $conversation->messages()->where('is_draft', false)->get();

        DB::transaction(function () use ($conversation, $messages, $state, $actor, $eventType) {
            $messages->each(fn (Message $message) => $message->forceFill([
                'mailbox_state' => array_merge($message->mailbox_state ?? [], $state),
            ])->save());

            $conversation->forceFill([
                'in_inbox' => $state['inInbox'],
                'is_archived' => $state['isArchived'],
                'is_trashed' => $state['isTrashed'],
                'is_spam' => $state['isSpam'],
                'last_activity_at' => now(),
            ])->save();

            $this->record($conversation, $eventType, $actor, ['state' => $state]);
        });

        $this->pushToProvider($conversation, $messages->pluck('id')->all(), $operation);
        $this->broadcast($conversation, 'mailbox');

        return $conversation;
    }

    /**
     * @param  array<int, int>  $messageIds
     */
    protected function pushToProvider(Conversation $conversation, array $messageIds, string $operation): void
    {
        $account = $conversation->mailAccount;

        if (! $account || $messageIds === []) {
            return;
        }

        PushMailboxChange::dispatch($account, $messageIds, $operation);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Conversation $conversation, ConversationEventType $type, ?User $actor, array $payload = []): ConversationEvent
    {
        $event = $conversation->events()->create([
            'actor_id' => $actor?->id,
            'type' => $type,
            'payload' => $payload,
        ]);

        ActivityAdded::dispatch($event);

        return $event;
    }

    public function broadcast(Conversation $conversation, string $reason): void
    {
        ConversationUpdated::dispatch($conversation->fresh(), $reason);
    }
}
