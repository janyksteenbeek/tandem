<?php

namespace App\Enums;

enum AutomationActionType: string
{
    case ReplyWithTemplate = 'reply_with_template';
    case AssignUser = 'assign_user';
    case AssignTeam = 'assign_team';
    case MoveToInbox = 'move_to_inbox';
    case AddTag = 'add_tag';
    case RemoveTag = 'remove_tag';
    case SetPriority = 'set_priority';
    case SetStatus = 'set_status';
    case NotifyUsers = 'notify_users';
    case AddFollower = 'add_follower';
    case AddComment = 'add_comment';

    public function label(): string
    {
        return match ($this) {
            self::ReplyWithTemplate => 'Reply with template',
            self::AssignUser => 'Assign to user',
            self::AssignTeam => 'Assign to team',
            self::MoveToInbox => 'Move to inbox',
            self::AddTag => 'Add tag',
            self::RemoveTag => 'Remove tag',
            self::SetPriority => 'Set priority',
            self::SetStatus => 'Set status',
            self::NotifyUsers => 'Notify users',
            self::AddFollower => 'Add follower',
            self::AddComment => 'Add internal comment',
        };
    }

    /**
     * Parameter schema the UI should render for this action.
     *
     * @return array<int, array{name: string, type: string, required: bool, options?: array<int, array{value: string, label: string}>}>
     */
    public function params(): array
    {
        return match ($this) {
            self::ReplyWithTemplate => [
                ['name' => 'template_id', 'type' => 'template', 'required' => true],
                ['name' => 'cooldown_minutes', 'type' => 'integer', 'required' => false],
            ],
            self::AssignUser, self::AddFollower => [
                ['name' => 'user_id', 'type' => 'user', 'required' => true],
            ],
            self::AssignTeam => [
                ['name' => 'team_id', 'type' => 'team', 'required' => true],
                ['name' => 'strategy', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'round_robin', 'label' => 'Round robin'],
                    ['value' => 'least_busy', 'label' => 'Least busy'],
                ]],
            ],
            self::MoveToInbox => [
                ['name' => 'inbox_uuid', 'type' => 'inbox', 'required' => true],
            ],
            self::AddTag, self::RemoveTag => [
                ['name' => 'tag_id', 'type' => 'tag', 'required' => false],
                ['name' => 'tag_name', 'type' => 'string', 'required' => false],
            ],
            self::SetPriority => [
                ['name' => 'priority', 'type' => 'select', 'required' => true, 'options' => ConversationPriority::options()],
            ],
            self::SetStatus => [
                ['name' => 'status', 'type' => 'select', 'required' => true, 'options' => collect(ConversationStatus::options())
                    ->reject(fn (array $option) => $option['value'] === ConversationStatus::Snoozed->value)
                    ->values()
                    ->all()],
            ],
            self::NotifyUsers => [
                ['name' => 'user_ids', 'type' => 'users', 'required' => true],
                ['name' => 'message', 'type' => 'text', 'required' => false],
            ],
            self::AddComment => [
                ['name' => 'body', 'type' => 'text', 'required' => true],
            ],
        };
    }

    /**
     * @return array<int, array{value: string, label: string, params: array<int, array<string, mixed>>}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $action) => ['value' => $action->value, 'label' => $action->label(), 'params' => $action->params()])
            ->all();
    }
}
