<?php

namespace App\Services\Automations;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Enums\ConversationEventType;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Events\Conversations\ConversationAssigned;
use App\Events\Conversations\ConversationMoved;
use App\Events\Conversations\ConversationStatusChanged;
use App\Events\Conversations\ConversationTagged;
use App\Models\AutomationRule;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AutomationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Executes one automation action against a conversation. Every mutation writes the same
 * ConversationEvent a human action would (with a null actor) and dispatches the matching domain
 * event, registering an echo so the engine never reacts to its own changes.
 */
class ActionExecutor
{
    public function __construct(
        protected Autoresponder $autoresponder,
        protected TeamAssigner $teamAssigner,
        protected TemplateRenderer $renderer,
        protected TriggerEchoGuard $echoes,
    ) {}

    /**
     * @param  array{type?: mixed, params?: mixed}  $action
     * @param  array{message?: Message|null}  $context
     * @return array<string, mixed>
     */
    public function execute(AutomationRule $rule, array $action, Conversation $conversation, array $context = []): array
    {
        $type = AutomationActionType::tryFrom(is_string($action['type'] ?? null) ? $action['type'] : '');

        if (! $type) {
            throw new InvalidArgumentException(sprintf('Unknown automation action "%s".', is_scalar($action['type'] ?? null) ? $action['type'] : ''));
        }

        $params = is_array($action['params'] ?? null) ? $action['params'] : [];

        $result = match ($type) {
            AutomationActionType::ReplyWithTemplate => $this->autoresponder->respond($rule, $conversation, $params, $context),
            AutomationActionType::AssignUser => $this->assignUser($rule, $conversation, $params),
            AutomationActionType::AssignTeam => $this->assignTeam($rule, $conversation, $params),
            AutomationActionType::MoveToInbox => $this->moveToInbox($rule, $conversation, $params),
            AutomationActionType::AddTag => $this->addTag($rule, $conversation, $params),
            AutomationActionType::RemoveTag => $this->removeTag($rule, $conversation, $params),
            AutomationActionType::SetPriority => $this->setPriority($rule, $conversation, $params),
            AutomationActionType::SetStatus => $this->setStatus($rule, $conversation, $params),
            AutomationActionType::NotifyUsers => $this->notifyUsers($rule, $conversation, $params),
            AutomationActionType::AddFollower => $this->addFollower($rule, $conversation, $params),
            AutomationActionType::AddComment => $this->addComment($rule, $conversation, $params, $context),
        };

        return ['type' => $type->value] + $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function assignUser(AutomationRule $rule, Conversation $conversation, array $params): array
    {
        $user = $this->organizationUser($conversation, $params['user_id'] ?? null);

        if (! $user) {
            return ['status' => 'skipped', 'reason' => 'user_not_found'];
        }

        return $this->assign($rule, $conversation, $user);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function assignTeam(AutomationRule $rule, Conversation $conversation, array $params): array
    {
        $team = Team::query()
            ->where('organization_id', $conversation->organization_id)
            ->find(is_numeric($params['team_id'] ?? null) ? (int) $params['team_id'] : 0);

        if (! $team) {
            return ['status' => 'skipped', 'reason' => 'team_not_found'];
        }

        $strategy = is_string($params['strategy'] ?? null) && in_array($params['strategy'], TeamAssigner::STRATEGIES, true)
            ? $params['strategy']
            : TeamAssigner::ROUND_ROBIN;

        $user = $this->teamAssigner->pick($team, $strategy, $conversation);

        if (! $user) {
            return ['status' => 'skipped', 'reason' => 'team_has_no_members', 'team_id' => $team->id];
        }

        return $this->assign($rule, $conversation, $user) + ['team_id' => $team->id, 'strategy' => $strategy];
    }

    /**
     * @return array<string, mixed>
     */
    protected function assign(AutomationRule $rule, Conversation $conversation, User $user): array
    {
        if ($conversation->assignee_id === $user->id) {
            return ['status' => 'skipped', 'reason' => 'already_assigned', 'user_id' => $user->id];
        }

        $previous = $conversation->assignee;

        $conversation->forceFill([
            'assignee_id' => $user->id,
            'assigned_by_id' => null,
            'assigned_at' => now(),
        ])->save();
        $conversation->setRelation('assignee', $user);

        $this->event($rule, $conversation, ConversationEventType::Assigned, ['assignee_id' => $user->id, 'previous_assignee_id' => $previous?->id]);

        $this->echoes->expect($conversation, AutomationTrigger::ConversationAssigned);
        ConversationAssigned::dispatch($conversation, $previous, $user, null);

        return ['status' => 'done', 'user_id' => $user->id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function moveToInbox(AutomationRule $rule, Conversation $conversation, array $params): array
    {
        $inbox = Inbox::query()
            ->where('organization_id', $conversation->organization_id)
            ->where('uuid', is_string($params['inbox_uuid'] ?? null) ? $params['inbox_uuid'] : '')
            ->first();

        if (! $inbox) {
            return ['status' => 'skipped', 'reason' => 'inbox_not_found'];
        }

        if ($conversation->inbox_id === $inbox->id) {
            return ['status' => 'skipped', 'reason' => 'already_in_inbox', 'inbox_uuid' => $inbox->uuid];
        }

        $from = $conversation->inbox;

        $conversation->forceFill([
            'inbox_id' => $inbox->id,
            'origin_inbox_id' => $conversation->origin_inbox_id ?? $from->id,
        ])->save();
        $conversation->setRelation('inbox', $inbox);

        $this->event($rule, $conversation, ConversationEventType::Moved, ['from_inbox_id' => $from->id, 'to_inbox_id' => $inbox->id]);

        $this->echoes->expect($conversation, AutomationTrigger::ConversationMoved);
        ConversationMoved::dispatch($conversation, $from, $inbox, null);

        return ['status' => 'done', 'inbox_uuid' => $inbox->uuid, 'from_inbox_uuid' => $from->uuid];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function addTag(AutomationRule $rule, Conversation $conversation, array $params): array
    {
        $tag = $this->resolveTag($conversation, $params, create: true);

        if (! $tag) {
            return ['status' => 'skipped', 'reason' => 'tag_not_found'];
        }

        if ($conversation->tags()->whereKey($tag->id)->exists()) {
            return ['status' => 'skipped', 'reason' => 'already_tagged', 'tag_id' => $tag->id];
        }

        $conversation->tags()->attach($tag->id);
        $conversation->unsetRelation('tags');

        $this->event($rule, $conversation, ConversationEventType::TagAdded, ['tag_id' => $tag->id, 'tag' => $tag->name]);

        $this->echoes->expect($conversation, AutomationTrigger::TagAdded);
        ConversationTagged::dispatch($conversation, $tag, null);

        return ['status' => 'done', 'tag_id' => $tag->id, 'tag' => $tag->name];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function removeTag(AutomationRule $rule, Conversation $conversation, array $params): array
    {
        $tag = $this->resolveTag($conversation, $params, create: false);

        if (! $tag) {
            return ['status' => 'skipped', 'reason' => 'tag_not_found'];
        }

        if ($conversation->tags()->detach($tag->id) === 0) {
            return ['status' => 'skipped', 'reason' => 'not_tagged', 'tag_id' => $tag->id];
        }

        $conversation->unsetRelation('tags');

        $this->event($rule, $conversation, ConversationEventType::TagRemoved, ['tag_id' => $tag->id, 'tag' => $tag->name]);

        return ['status' => 'done', 'tag_id' => $tag->id, 'tag' => $tag->name];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function setPriority(AutomationRule $rule, Conversation $conversation, array $params): array
    {
        $key = is_string($params['priority'] ?? null) ? $params['priority'] : '';
        $priority = ConversationPriority::fromKey($key);

        if ($conversation->priority === $priority) {
            return ['status' => 'skipped', 'reason' => 'unchanged', 'priority' => $priority->key()];
        }

        $previous = $conversation->priority;
        $conversation->forceFill(['priority' => $priority])->save();

        $this->event($rule, $conversation, ConversationEventType::PriorityChanged, ['from' => $previous->key(), 'to' => $priority->key()]);

        return ['status' => 'done', 'priority' => $priority->key()];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function setStatus(AutomationRule $rule, Conversation $conversation, array $params): array
    {
        $status = ConversationStatus::tryFrom(is_string($params['status'] ?? null) ? $params['status'] : '');

        if (! $status || $status === ConversationStatus::Snoozed) {
            return ['status' => 'skipped', 'reason' => 'invalid_status'];
        }

        if ($conversation->status === $status) {
            return ['status' => 'skipped', 'reason' => 'unchanged', 'conversation_status' => $status->value];
        }

        $previous = $conversation->status;

        $conversation->forceFill([
            'status' => $status,
            'closed_at' => $status === ConversationStatus::Closed ? now() : null,
            'closed_by_id' => null,
            'snoozed_until' => null,
            'snoozed_by_id' => null,
        ])->save();

        $this->event($rule, $conversation, ConversationEventType::StatusChanged, ['from' => $previous->value, 'to' => $status->value]);

        if ($status === ConversationStatus::Closed) {
            $this->echoes->expect($conversation, AutomationTrigger::ConversationClosed);
        } elseif ($previous === ConversationStatus::Closed && $status === ConversationStatus::Open) {
            $this->echoes->expect($conversation, AutomationTrigger::ConversationReopened);
        }

        ConversationStatusChanged::dispatch($conversation, $previous, $status, null);

        return ['status' => 'done', 'conversation_status' => $status->value];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function notifyUsers(AutomationRule $rule, Conversation $conversation, array $params): array
    {
        $ids = collect(is_array($params['user_ids'] ?? null) ? $params['user_ids'] : [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $users = $ids->isEmpty() || ! $conversation->organization
            ? collect()
            : $conversation->organization->members()->whereIn('users.id', $ids->all())->get();

        if ($users->isEmpty()) {
            return ['status' => 'skipped', 'reason' => 'no_recipients'];
        }

        $message = is_string($params['message'] ?? null) && trim($params['message']) !== ''
            ? Str::limit(trim($params['message']), 500)
            : null;

        Notification::send($users, new AutomationNotification($rule, $conversation, $message));

        return ['status' => 'done', 'user_ids' => $users->pluck('id')->all()];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function addFollower(AutomationRule $rule, Conversation $conversation, array $params): array
    {
        $user = $this->organizationUser($conversation, $params['user_id'] ?? null);

        if (! $user) {
            return ['status' => 'skipped', 'reason' => 'user_not_found'];
        }

        if ($conversation->followers()->whereKey($user->id)->exists()) {
            return ['status' => 'skipped', 'reason' => 'already_following', 'user_id' => $user->id];
        }

        $conversation->followers()->attach($user->id);
        $conversation->unsetRelation('followers');

        $this->event($rule, $conversation, ConversationEventType::FollowerAdded, ['user_id' => $user->id]);

        return ['status' => 'done', 'user_id' => $user->id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{message?: Message|null}  $context
     * @return array<string, mixed>
     */
    protected function addComment(AutomationRule $rule, Conversation $conversation, array $params, array $context): array
    {
        $template = is_string($params['body'] ?? null) ? trim($params['body']) : '';

        if ($template === '') {
            return ['status' => 'skipped', 'reason' => 'empty_body'];
        }

        $author = $this->autoresponder->sendingUser($rule, $conversation);

        if (! $author) {
            return ['status' => 'skipped', 'reason' => 'no_author'];
        }

        $comment = $conversation->comments()->create([
            'user_id' => $author->id,
            'body' => $this->renderer->render($template, $conversation, $context),
            'mentions' => [],
        ]);

        $conversation->forceFill(['comment_count' => $conversation->comments()->count()])->save();

        $this->event($rule, $conversation, ConversationEventType::CommentAdded, ['comment_id' => $comment->id, 'user_id' => $author->id]);

        return ['status' => 'done', 'comment_id' => $comment->id];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function resolveTag(Conversation $conversation, array $params, bool $create): ?Tag
    {
        $query = Tag::query()->where('organization_id', $conversation->organization_id);

        if (is_numeric($params['tag_id'] ?? null)) {
            return $query->find((int) $params['tag_id']);
        }

        $name = is_string($params['tag_name'] ?? null) ? trim($params['tag_name']) : '';

        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name) ?: Str::lower($name);
        $existing = (clone $query)->where(fn ($q) => $q->where('slug', $slug)->orWhereRaw('LOWER(name) = ?', [Str::lower($name)]))->first();

        if ($existing || ! $create) {
            return $existing;
        }

        return Tag::create([
            'organization_id' => $conversation->organization_id,
            'name' => Str::limit($name, 60, ''),
            'slug' => $slug,
            'color' => 'slate',
        ]);
    }

    protected function organizationUser(Conversation $conversation, mixed $userId): ?User
    {
        if (! is_numeric($userId) || ! $conversation->organization) {
            return null;
        }

        $user = $conversation->organization->members()->whereKey((int) $userId)->first();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function event(AutomationRule $rule, Conversation $conversation, ConversationEventType $type, array $payload): void
    {
        $conversation->events()->create([
            'type' => $type,
            'actor_id' => null,
            'payload' => $payload + ['automation_rule_id' => $rule->id, 'automation_rule_name' => $rule->name],
        ]);
    }
}
