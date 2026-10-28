<?php

namespace App\Services\Conversations;

use App\Enums\ConversationEventType;
use App\Events\Broadcast\ConversationUpdated;
use App\Events\Conversations\ConversationMoved;
use App\Events\Conversations\ConversationShared;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sharing makes a conversation visible to a team inbox while it keeps living in its origin
 * (nothing moves at the provider). Transferring changes the owning inbox inside Tandem.
 * Neither touches the external mailbox: the user's Gmail/IMAP account is untouched.
 */
class ConversationSharing
{
    public function __construct(protected ConversationActions $actions) {}

    public function share(Conversation $conversation, Inbox $target, User $actor, ?string $note = null): Conversation
    {
        $this->assertShareable($conversation, $target);

        if ($conversation->inbox_id === $target->id || $conversation->shares()->where('inbox_id', $target->id)->exists()) {
            return $conversation;
        }

        DB::transaction(function () use ($conversation, $target, $actor, $note) {
            $conversation->shares()->create([
                'inbox_id' => $target->id,
                'shared_by_id' => $actor->id,
                'note' => $note,
            ]);

            if ($conversation->organization_id === null && $target->organization_id) {
                $conversation->forceFill(['organization_id' => $target->organization_id])->save();
            }

            $conversation->followers()->syncWithoutDetaching([$actor->id]);

            $this->actions->record($conversation, ConversationEventType::Shared, $actor, [
                'inbox' => ['uuid' => $target->uuid, 'name' => $target->name],
                'note' => $note,
            ]);
        });

        ConversationShared::dispatch($conversation, $target, $actor);
        ConversationUpdated::dispatch($conversation->fresh(), 'shared');

        return $conversation;
    }

    public function unshare(Conversation $conversation, Inbox $target, User $actor): Conversation
    {
        $deleted = $conversation->shares()->where('inbox_id', $target->id)->delete();

        if ($deleted) {
            $this->actions->record($conversation, ConversationEventType::Unshared, $actor, ['inbox' => ['uuid' => $target->uuid, 'name' => $target->name]]);
            ConversationUpdated::dispatch($conversation->fresh(), 'unshared', [$target->id]);
        }

        return $conversation;
    }

    /**
     * Move the conversation so the target inbox owns it. Permissions change immediately because
     * visibility is derived from `inbox_id` + shares at query time.
     */
    public function transfer(Conversation $conversation, Inbox $target, User $actor): Conversation
    {
        $this->assertShareable($conversation, $target);

        $from = $conversation->inbox;

        if ($from->id === $target->id) {
            return $conversation;
        }

        DB::transaction(function () use ($conversation, $target, $actor, $from) {
            $conversation->forceFill([
                'inbox_id' => $target->id,
                'origin_inbox_id' => $conversation->origin_inbox_id ?? $from->id,
                'organization_id' => $target->organization_id ?? $conversation->organization_id,
                'assignee_id' => null,
                'assigned_at' => null,
                'assigned_by_id' => null,
                'last_activity_at' => now(),
            ])->save();

            $conversation->shares()->where('inbox_id', $target->id)->delete();
            $conversation->followers()->syncWithoutDetaching([$actor->id]);

            if ($from->isPersonal() && $target->isShared()) {
                // Keep a share back into the owner's personal inbox so they never lose their own thread.
                $conversation->shares()->firstOrCreate(['inbox_id' => $from->id], ['shared_by_id' => $actor->id, 'note' => 'Origin']);
            }

            $this->actions->record($conversation, ConversationEventType::Moved, $actor, [
                'from' => ['uuid' => $from->uuid, 'name' => $from->name],
                'to' => ['uuid' => $target->uuid, 'name' => $target->name],
            ]);
        });

        ConversationMoved::dispatch($conversation->fresh(), $from, $target, $actor);
        ConversationUpdated::dispatch($conversation->fresh(), 'moved', [$from->id]);

        return $conversation;
    }

    protected function assertShareable(Conversation $conversation, Inbox $target): void
    {
        if ($target->isPersonal() && $conversation->inbox->isPersonal()) {
            throw new InvalidArgumentException('Conversations cannot be shared between two personal inboxes.');
        }
    }
}
