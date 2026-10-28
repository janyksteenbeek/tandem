<?php

namespace App\Http\Controllers\Mail;

use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\InboxType;
use App\Enums\MailboxView;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationListItemResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\InboxResource;
use App\Http\Resources\TagResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\SavedView;
use App\Models\Tag;
use App\Models\User;
use App\Services\Conversations\ConversationQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class InboxViewController extends Controller
{
    public function __construct(protected ConversationQuery $conversations) {}

    /**
     * The three-pane mail screen for one inbox (or every personal inbox when $inbox is "all").
     */
    public function show(Request $request, string $inbox, string $view = 'inbox', ?Conversation $conversation = null): Response
    {
        $user = $request->user();
        $organization = $user->currentOrganization;

        if ($inbox === 'all') {
            $inboxes = $user->personalInboxes()->with('mailAccounts.identities')->get();
            $primary = null;
            $type = InboxType::Personal;
        } else {
            $primary = Inbox::query()->where('uuid', $inbox)->with('mailAccounts.identities')->firstOrFail();
            $this->authorize('view', $primary);
            $inboxes = collect([$primary]);
            $type = $primary->type;
        }

        $views = $type === InboxType::Personal ? MailboxView::personal() : MailboxView::shared();
        $current = MailboxView::tryFrom($view) ?? ($type === InboxType::Personal ? MailboxView::Inbox : MailboxView::Open);

        if (! in_array($current, $views, true)) {
            $current = $views[0];
        }

        $filters = $request->only(['q', 'assignee', 'status', 'priority', 'tag', 'unread', 'attachments', 'from', 'after', 'before', 'sort']);

        if ($conversation) {
            $this->authorize('view', $conversation);
        }

        $members = $this->members($inboxes, $user);

        return Inertia::render('mail/Inbox', [
            'inbox' => $primary ? InboxResource::make($primary)->resolve() : [
                'id' => 0,
                'uuid' => 'all',
                'type' => 'personal',
                'name' => 'All personal mail',
                'slug' => 'all',
                'color' => 'violet',
                'icon' => 'layers',
                'email_address' => null,
                'role' => 'admin',
                'accounts' => $inboxes->flatMap->mailAccounts->map(fn ($account) => [
                    'uuid' => $account->uuid,
                    'provider' => $account->provider->value,
                    'email' => $account->email_address,
                    'display_name' => $account->display_name,
                    'status' => $account->status->value,
                    'last_synced_at' => $account->last_synced_at?->toISOString(),
                    'last_error' => $account->last_error,
                    'identities' => $account->identities->map(fn ($identity) => [
                        'id' => $identity->id,
                        'name' => $identity->name,
                        'email' => $identity->email,
                        'is_default' => $identity->is_default,
                        'signature_html' => $identity->signature_html,
                    ])->values(),
                ])->values(),
            ],
            'view' => $current->value,
            'views' => collect($views)->map(fn (MailboxView $v) => ['value' => $v->value, 'label' => $v->label()])->values(),
            'counts' => Inertia::defer(fn () => $this->conversations->counts($inboxes, $views, $user)),
            'filters' => $filters,
            'conversations' => Inertia::merge(fn () => ConversationListItemResource::collection(
                $this->conversations->paginate($inboxes, $current, $user, $filters)
            )->response($request)->getData(true))->matchOn('data.uuid'),
            'conversation' => $conversation ? ConversationResource::make($conversation)->resolve() : null,
            'members' => $members,
            'tags' => $this->tags($primary, $user),
            'savedViews' => $this->savedViews($primary, $user),
            'statuses' => ConversationStatus::options(),
            'priorities' => ConversationPriority::options(),
            'shareTargets' => Inertia::optional(fn () => $this->shareTargets($user, $primary)),
        ]);
    }

    /**
     * People who can be assigned/mentioned in this inbox.
     *
     * @param  Collection<int, Inbox>  $inboxes
     * @return array<int, array<string, mixed>>
     */
    protected function members(Collection $inboxes, User $user): array
    {
        $orgIds = $inboxes->pluck('organization_id')->filter()->unique();

        if ($orgIds->isEmpty()) {
            return [UserSummaryResource::make($user)->resolve()];
        }

        return User::query()
            ->whereHas('organizations', fn (Builder $q) => $q->whereIn('organizations.id', $orgIds))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $member) => $inboxes->contains(fn (Inbox $inbox) => $inbox->roleFor($member) !== null))
            ->map(fn (User $member) => UserSummaryResource::make($member)->resolve())
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function tags(?Inbox $inbox, User $user): array
    {
        $query = $inbox?->organization_id
            ? Tag::query()->where('organization_id', $inbox->organization_id)
            : Tag::query()->whereNull('organization_id')->where('user_id', $user->id);

        return TagResource::collection($query->orderBy('name')->get())->resolve();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function savedViews(?Inbox $inbox, User $user): array
    {
        return SavedView::query()
            ->where(function (Builder $q) use ($inbox, $user) {
                $q->where('user_id', $user->id);

                if ($inbox?->organization_id) {
                    $q->orWhere(fn (Builder $shared) => $shared->where('organization_id', $inbox->organization_id)->where('is_shared', true));
                }
            })
            ->when($inbox, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('inbox_id', $inbox->id)->orWhereNull('inbox_id')))
            ->orderBy('position')
            ->get()
            ->map(fn (SavedView $view) => [
                'id' => $view->id,
                'name' => $view->name,
                'icon' => $view->icon,
                'filters' => $view->filters,
                'is_shared' => $view->is_shared,
                'inbox_id' => $view->inbox_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Shared inboxes the user may share/move conversations into.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function shareTargets(User $user, ?Inbox $current): array
    {
        return Inbox::query()
            ->accessibleBy($user)
            ->where('type', InboxType::Shared->value)
            ->when($current, fn (Builder $q) => $q->whereKeyNot($current->id))
            ->orderBy('name')
            ->get()
            ->map(fn (Inbox $inbox) => ['uuid' => $inbox->uuid, 'name' => $inbox->name, 'color' => $inbox->color, 'email' => $inbox->email_address])
            ->values()
            ->all();
    }
}
