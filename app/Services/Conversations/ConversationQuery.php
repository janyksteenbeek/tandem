<?php

namespace App\Services\Conversations;

use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\MailboxView;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds the middle-pane list for an inbox (or several) and a view, with filters and search.
 */
class ConversationQuery
{
    /**
     * @param  Collection<int, Inbox>  $inboxes
     * @param  array<string, mixed>  $filters
     * @return Builder<Conversation>
     */
    public function build(Collection $inboxes, MailboxView $view, User $user, array $filters = []): Builder
    {
        $inboxIds = $inboxes->pluck('id')->all();

        $query = Conversation::query()
            ->with(['assignee', 'tags', 'inbox.mailAccounts', 'shares'])
            ->where(function (Builder $q) use ($inboxIds) {
                $q->whereIn('inbox_id', $inboxIds)
                    ->orWhereHas('shares', fn (Builder $shares) => $shares->whereIn('inbox_id', $inboxIds));
            });

        $this->applyView($query, $view, $user);
        $this->applyFilters($query, $filters, $user);

        $sort = $filters['sort'] ?? 'newest';

        return match ($sort) {
            'oldest' => $query->orderBy('last_message_at')->orderBy('id'),
            'priority' => $query->orderByDesc('priority')->orderByDesc('last_message_at')->orderByDesc('id'),
            default => $query->orderByDesc('last_message_at')->orderByDesc('id'),
        };
    }

    /**
     * @param  Collection<int, Inbox>  $inboxes
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Conversation>
     */
    public function paginate(Collection $inboxes, MailboxView $view, User $user, array $filters = [], int $perPage = 40): CursorPaginator
    {
        return $this->build($inboxes, $view, $user, $filters)->cursorPaginate($perPage)->withQueryString();
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    public function applyView(Builder $query, MailboxView $view, User $user): void
    {
        $notJunk = fn (Builder $q) => $q->where('is_trashed', false)->where('is_spam', false);

        match ($view) {
            MailboxView::Inbox => $notJunk($query)->where('in_inbox', true)->where('status', '!=', ConversationStatus::Snoozed->value),
            MailboxView::Starred => $notJunk($query)->where('is_starred', true),
            MailboxView::Snoozed => $query->where('status', ConversationStatus::Snoozed->value),
            MailboxView::Drafts => $query->whereHas('drafts', fn (Builder $d) => $d->where(fn (Builder $w) => $w->where('user_id', $user->id)->orWhere('is_shared', true))),
            MailboxView::Sent => $notJunk($query)->whereNotNull('last_outbound_at'),
            MailboxView::Archive => $notJunk($query)->where('is_archived', true),
            MailboxView::Spam => $query->where('is_spam', true),
            MailboxView::Trash => $query->where('is_trashed', true),
            MailboxView::All => $notJunk($query),
            MailboxView::Open => $notJunk($query)->where('status', ConversationStatus::Open->value),
            MailboxView::Assigned => $notJunk($query)->where('assignee_id', $user->id)->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::Pending->value]),
            MailboxView::Unassigned => $notJunk($query)->whereNull('assignee_id')->where('status', ConversationStatus::Open->value),
            MailboxView::Pending => $notJunk($query)->where('status', ConversationStatus::Pending->value),
            MailboxView::Closed => $notJunk($query)->where('status', ConversationStatus::Closed->value),
        };
    }

    /**
     * @param  Builder<Conversation>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters, User $user): void
    {
        if (filled($filters['q'] ?? null)) {
            $query->search((string) $filters['q']);
        }

        if (filled($filters['assignee'] ?? null)) {
            $assignee = $filters['assignee'];

            match ($assignee) {
                'me' => $query->where('assignee_id', $user->id),
                'none' => $query->whereNull('assignee_id'),
                default => $query->where('assignee_id', (int) $assignee),
            };
        }

        if (filled($filters['status'] ?? null) && ($status = ConversationStatus::tryFrom((string) $filters['status']))) {
            $query->where('status', $status->value);
        }

        if (filled($filters['priority'] ?? null)) {
            $query->where('priority', ConversationPriority::fromKey((string) $filters['priority'])->value);
        }

        if (filled($filters['tag'] ?? null)) {
            $tags = is_array($filters['tag']) ? $filters['tag'] : [$filters['tag']];
            $query->whereHas('tags', fn (Builder $t) => $t->whereIn('tags.id', $tags)->orWhereIn('tags.slug', $tags));
        }

        if (($filters['unread'] ?? null) === '1' || ($filters['unread'] ?? null) === true) {
            $query->where('is_read', false);
        }

        if (($filters['attachments'] ?? null) === '1' || ($filters['attachments'] ?? null) === true) {
            $query->where('has_attachments', true);
        }

        if (filled($filters['from'] ?? null)) {
            $query->whereRaw('JSON_SEARCH(LOWER(participants), "one", ?) IS NOT NULL', ['%'.strtolower((string) $filters['from']).'%']);
        }

        if (filled($filters['after'] ?? null)) {
            $query->where('last_message_at', '>=', $filters['after']);
        }

        if (filled($filters['before'] ?? null)) {
            $query->where('last_message_at', '<=', $filters['before']);
        }
    }

    /**
     * Badge counts for the view switcher of an inbox.
     *
     * @param  Collection<int, Inbox>  $inboxes
     * @param  array<int, MailboxView>  $views
     * @return array<string, int>
     */
    public function counts(Collection $inboxes, array $views, User $user): array
    {
        $counts = [];

        foreach ($views as $view) {
            $query = $this->build($inboxes, $view, $user)->reorder();

            $counts[$view->value] = match ($view) {
                MailboxView::Inbox, MailboxView::All, MailboxView::Archive, MailboxView::Starred => (clone $query)->where('is_read', false)->count(),
                MailboxView::Sent, MailboxView::Trash, MailboxView::Spam, MailboxView::Closed => 0,
                default => $query->count(),
            };
        }

        return $counts;
    }
}
