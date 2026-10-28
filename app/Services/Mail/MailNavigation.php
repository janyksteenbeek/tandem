<?php

namespace App\Services\Mail;

use App\Enums\ConversationStatus;
use App\Enums\InboxType;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sidebar data: the inboxes a user can see in the current organization with their counters.
 */
class MailNavigation
{
    /**
     * @return array<string, mixed>
     */
    public function for(User $user, ?Organization $organization): array
    {
        $personal = $user->personalInboxes()->with('mailAccounts')->get();

        $shared = $organization
            ? Inbox::query()->accessibleBy($user)->where('organization_id', $organization->id)->where('type', InboxType::Shared->value)->with(['mailAccounts', 'teams'])->orderBy('position')->orderBy('name')->get()
            : collect();

        $personalCounts = $this->unreadCounts($personal);
        $sharedCounts = $this->openCounts($shared);

        $assignedToMe = $shared->isEmpty() ? 0 : Conversation::query()
            ->whereIn('inbox_id', $shared->pluck('id'))
            ->where('assignee_id', $user->id)
            ->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::Pending->value])
            ->where('is_trashed', false)
            ->count();

        return [
            'personal' => $personal->map(fn (Inbox $inbox) => $this->inboxItem($inbox, $personalCounts[$inbox->id] ?? 0))->values()->all(),
            'shared' => $shared->map(fn (Inbox $inbox) => $this->inboxItem($inbox, $sharedCounts[$inbox->id] ?? 0))->values()->all(),
            'assigned_to_me' => $assignedToMe,
            'personal_unread' => array_sum($personalCounts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function inboxItem(Inbox $inbox, int $count): array
    {
        return [
            'id' => $inbox->id,
            'uuid' => $inbox->uuid,
            'type' => $inbox->type->value,
            'name' => $inbox->name,
            'email' => $inbox->email_address ?? $inbox->mailAccounts->first()?->email_address,
            'color' => $inbox->color,
            'icon' => $inbox->icon,
            'count' => $count,
            'providers' => $inbox->mailAccounts->pluck('provider')->map(fn ($p) => $p->value)->unique()->values()->all(),
            'health' => $inbox->mailAccounts->contains(fn ($account) => ! $account->status->isHealthy()) ? 'attention' : 'ok',
        ];
    }

    /**
     * @param  Collection<int, Inbox>  $inboxes
     * @return array<int, int>
     */
    protected function unreadCounts(Collection $inboxes): array
    {
        if ($inboxes->isEmpty()) {
            return [];
        }

        return Conversation::query()
            ->selectRaw('inbox_id, COUNT(*) as aggregate')
            ->whereIn('inbox_id', $inboxes->pluck('id'))
            ->where('in_inbox', true)
            ->where('is_read', false)
            ->where('is_trashed', false)
            ->where('is_spam', false)
            ->where('status', '!=', ConversationStatus::Snoozed->value)
            ->groupBy('inbox_id')
            ->pluck('aggregate', 'inbox_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  Collection<int, Inbox>  $inboxes
     * @return array<int, int>
     */
    protected function openCounts(Collection $inboxes): array
    {
        if ($inboxes->isEmpty()) {
            return [];
        }

        $ids = $inboxes->pluck('id');

        $owned = Conversation::query()
            ->selectRaw('inbox_id, COUNT(*) as aggregate')
            ->whereIn('inbox_id', $ids)
            ->where('status', ConversationStatus::Open->value)
            ->where('is_trashed', false)
            ->where('is_spam', false)
            ->groupBy('inbox_id')
            ->pluck('aggregate', 'inbox_id');

        $shared = Conversation::query()
            ->join('conversation_shares', 'conversation_shares.conversation_id', '=', 'conversations.id')
            ->selectRaw('conversation_shares.inbox_id as inbox_id, COUNT(*) as aggregate')
            ->whereIn('conversation_shares.inbox_id', $ids)
            ->where('conversations.status', ConversationStatus::Open->value)
            ->where('conversations.is_trashed', false)
            ->whereNull('conversations.deleted_at')
            ->groupBy('conversation_shares.inbox_id')
            ->pluck('aggregate', 'inbox_id');

        return $ids->mapWithKeys(fn (int $id) => [$id => (int) ($owned[$id] ?? 0) + (int) ($shared[$id] ?? 0)])->all();
    }

    /**
     * Resolve the inbox a user should land on.
     */
    public function defaultInbox(User $user, ?Organization $organization): ?Inbox
    {
        $preferred = $user->preference('default_inbox_uuid');

        if ($preferred) {
            $inbox = Inbox::query()->where('uuid', $preferred)->first();

            if ($inbox && $user->can('view', $inbox)) {
                return $inbox;
            }
        }

        return $user->personalInboxes()->first()
            ?? ($organization ? Inbox::query()->accessibleBy($user)->where('organization_id', $organization->id)->orderBy('position')->first() : null);
    }
}
