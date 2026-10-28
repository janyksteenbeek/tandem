<?php

namespace App\Http\Controllers\Mail;

use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationListItemResource;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Tag;
use App\Models\User;
use App\Services\Conversations\ConversationActions;
use App\Services\Conversations\ConversationSharing;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function __construct(
        protected ConversationActions $actions,
        protected ConversationSharing $sharing,
    ) {}

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        return response()->json(['conversation' => ConversationResource::make($conversation)->resolve()]);
    }

    public function assign(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('act', $conversation);

        $data = $request->validate(['assignee_id' => ['nullable', 'integer', 'exists:users,id']]);

        $assignee = $data['assignee_id'] ? User::query()->whereKey((int) $data['assignee_id'])->firstOrFail() : null;

        abort_if($assignee && ! $assignee->can('view', $conversation), 422, 'That person cannot see this inbox.');

        return $this->respond($this->actions->assign($conversation, $assignee, $request->user()));
    }

    public function status(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('act', $conversation);

        $data = $request->validate([
            'status' => ['required', Rule::enum(ConversationStatus::class)],
            'snoozed_until' => ['required_if:status,snoozed', 'nullable', 'date', 'after:now'],
        ]);

        $status = ConversationStatus::from($data['status']);
        $until = isset($data['snoozed_until']) ? CarbonImmutable::parse($data['snoozed_until']) : null;

        return $this->respond($this->actions->setStatus($conversation, $status, $request->user(), $until));
    }

    public function priority(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('act', $conversation);

        $data = $request->validate(['priority' => ['required', Rule::in(array_column(ConversationPriority::options(), 'value'))]]);

        return $this->respond($this->actions->setPriority($conversation, ConversationPriority::fromKey($data['priority']), $request->user()));
    }

    public function tags(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('act', $conversation);

        $data = $request->validate([
            'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
            'name' => ['nullable', 'string', 'max:60'],
            'attached' => ['required', 'boolean'],
        ]);

        $tag = isset($data['tag_id']) ? Tag::query()->whereKey((int) $data['tag_id'])->firstOrFail() : $this->findOrCreateTag($conversation, $request->user(), (string) ($data['name'] ?? ''));

        abort_unless($this->tagBelongsTo($tag, $conversation, $request->user()), 422, 'Tag is not available in this inbox.');

        $conversation = $data['attached']
            ? $this->actions->addTag($conversation, $tag, $request->user())
            : $this->actions->removeTag($conversation, $tag, $request->user());

        return $this->respond($conversation);
    }

    public function star(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('act', $conversation);

        $data = $request->validate(['starred' => ['required', 'boolean']]);

        return $this->respond($this->actions->setStarred($conversation, $data['starred'], $request->user()));
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $data = $request->validate(['read' => ['required', 'boolean']]);

        return $this->respond($this->actions->markRead($conversation, $data['read'], $request->user()));
    }

    public function mailbox(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('act', $conversation);

        $data = $request->validate(['action' => ['required', Rule::in(['archive', 'inbox', 'trash', 'restore', 'spam', 'not_spam'])]]);
        $user = $request->user();

        $conversation = match ((string) $data['action']) {
            'archive' => $this->actions->archive($conversation, $user),
            'inbox' => $this->actions->moveToInbox($conversation, $user),
            'trash' => $this->actions->trash($conversation, $user),
            'restore' => $this->actions->restore($conversation, $user),
            'spam' => $this->actions->markSpam($conversation, true, $user),
            'not_spam' => $this->actions->markSpam($conversation, false, $user),
            default => $conversation,
        };

        return $this->respond($conversation);
    }

    public function follow(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $data = $request->validate(['following' => ['required', 'boolean']]);

        return $this->respond($this->actions->follow($conversation, $request->user(), $data['following']));
    }

    public function share(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('share', $conversation);

        $data = $request->validate([
            'inbox' => ['required', 'string', 'exists:inboxes,uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
            'mode' => ['required', Rule::in(['share', 'transfer'])],
        ]);

        $target = Inbox::query()->where('uuid', $data['inbox'])->firstOrFail();
        $this->authorize('act', $target);

        $conversation = $data['mode'] === 'transfer'
            ? $this->sharing->transfer($conversation, $target, $request->user())
            : $this->sharing->share($conversation, $target, $request->user(), $data['note'] ?? null);

        return $this->respond($conversation);
    }

    public function unshare(Request $request, Conversation $conversation, Inbox $inbox): JsonResponse
    {
        $this->authorize('share', $conversation);

        return $this->respond($this->sharing->unshare($conversation, $inbox, $request->user()));
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);

        $this->actions->delete($conversation, $request->user());

        return response()->json(['deleted' => true]);
    }

    /**
     * Apply one action to many conversations at once.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversations' => ['required', 'array', 'max:200'],
            'conversations.*' => ['string'],
            'action' => ['required', Rule::in(['assign', 'status', 'priority', 'tag', 'untag', 'star', 'unstar', 'read', 'unread', 'archive', 'inbox', 'trash', 'restore', 'spam', 'not_spam'])],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::enum(ConversationStatus::class)],
            'snoozed_until' => ['nullable', 'date'],
            'priority' => ['nullable', 'string'],
            'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
        ]);

        $user = $request->user();
        $assignee = isset($data['assignee_id']) ? User::query()->whereKey((int) $data['assignee_id'])->first() : null;
        $tag = isset($data['tag_id']) ? Tag::query()->whereKey((int) $data['tag_id'])->first() : null;
        $updated = [];

        Conversation::query()->whereIn('uuid', $data['conversations'])->get()->each(function (Conversation $conversation) use ($data, $user, $assignee, $tag, &$updated) {
            if (! $user->can('act', $conversation)) {
                return;
            }

            $result = match ((string) $data['action']) {
                'assign' => $this->actions->assign($conversation, $assignee, $user),
                'status' => $this->actions->setStatus($conversation, ConversationStatus::from($data['status']), $user, isset($data['snoozed_until']) ? CarbonImmutable::parse($data['snoozed_until']) : null),
                'priority' => $this->actions->setPriority($conversation, ConversationPriority::fromKey((string) ($data['priority'] ?? 'none')), $user),
                'tag' => $tag ? $this->actions->addTag($conversation, $tag, $user) : $conversation,
                'untag' => $tag ? $this->actions->removeTag($conversation, $tag, $user) : $conversation,
                'star' => $this->actions->setStarred($conversation, true, $user),
                'unstar' => $this->actions->setStarred($conversation, false, $user),
                'read' => $this->actions->markRead($conversation, true, $user),
                'unread' => $this->actions->markRead($conversation, false, $user),
                'archive' => $this->actions->archive($conversation, $user),
                'inbox' => $this->actions->moveToInbox($conversation, $user),
                'trash' => $this->actions->trash($conversation, $user),
                'restore' => $this->actions->restore($conversation, $user),
                'spam' => $this->actions->markSpam($conversation, true, $user),
                'not_spam' => $this->actions->markSpam($conversation, false, $user),
                default => $conversation,
            };

            $updated[] = ConversationListItemResource::make($result->fresh(['assignee', 'tags', 'inbox.mailAccounts', 'shares']))->resolve();
        });

        return response()->json(['conversations' => $updated]);
    }

    protected function respond(Conversation $conversation): JsonResponse
    {
        return response()->json(['conversation' => ConversationResource::make($conversation->fresh())->resolve()]);
    }

    protected function findOrCreateTag(Conversation $conversation, User $user, string $name): Tag
    {
        abort_if(trim($name) === '', 422, 'A tag name is required.');

        $attributes = $conversation->organization_id
            ? ['organization_id' => $conversation->organization_id, 'user_id' => null]
            : ['organization_id' => null, 'user_id' => $user->id];

        return Tag::query()->firstOrCreate(
            [...$attributes, 'slug' => Str::slug($name)],
            ['name' => trim($name), 'color' => collect(['violet', 'teal', 'amber', 'rose', 'sky', 'emerald'])->random()],
        );
    }

    protected function tagBelongsTo(Tag $tag, Conversation $conversation, User $user): bool
    {
        if ($conversation->organization_id) {
            return $tag->organization_id === $conversation->organization_id;
        }

        return $tag->organization_id === null && $tag->user_id === $user->id;
    }
}
