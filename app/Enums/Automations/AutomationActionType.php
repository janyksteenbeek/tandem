<?php

namespace App\Enums\Automations;

use App\Enums\Mail\ConversationPriority;
use App\Enums\Mail\ConversationStatus;
use App\Enums\Projects\IssuePriority;

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

    // Mail → issues
    case CreateIssue = 'create_issue';
    case LinkIssue = 'link_issue';

    // Issues
    case SetIssueStatus = 'set_issue_status';
    case SetIssuePriority = 'set_issue_priority';
    case SetIssueOwner = 'set_issue_owner';
    case AddTeamToIssue = 'add_team_to_issue';
    case AssignWorkstream = 'assign_workstream';
    case SetWorkstreamStatus = 'set_workstream_status';
    case AddIssueLabel = 'add_issue_label';
    case RemoveIssueLabel = 'remove_issue_label';
    case AddIssueComment = 'add_issue_comment';
    case AddIssueFollower = 'add_issue_follower';
    case MoveIssueToProject = 'move_issue_to_project';

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
            self::CreateIssue => 'Create issue',
            self::LinkIssue => 'Link existing issue',
            self::SetIssueStatus => 'Set overall status',
            self::SetIssuePriority => 'Set priority',
            self::SetIssueOwner => 'Set owner',
            self::AddTeamToIssue => 'Add team',
            self::AssignWorkstream => 'Assign team member',
            self::SetWorkstreamStatus => "Set a team's status",
            self::AddIssueLabel => 'Add label',
            self::RemoveIssueLabel => 'Remove label',
            self::AddIssueComment => 'Add comment',
            self::AddIssueFollower => 'Add follower',
            self::MoveIssueToProject => 'Move to project',
        };
    }

    /**
     * @return array<int, AutomationSubjectType>
     */
    public function subjectTypes(): array
    {
        return match ($this) {
            self::NotifyUsers => [AutomationSubjectType::Conversation, AutomationSubjectType::Issue],
            self::SetIssueStatus, self::SetIssuePriority, self::SetIssueOwner, self::AddTeamToIssue, self::AssignWorkstream, self::SetWorkstreamStatus,
            self::AddIssueLabel, self::RemoveIssueLabel, self::AddIssueComment, self::AddIssueFollower, self::MoveIssueToProject => [AutomationSubjectType::Issue],
            default => [AutomationSubjectType::Conversation],
        };
    }

    public function supports(AutomationSubjectType $subject): bool
    {
        return in_array($subject, $this->subjectTypes(), true);
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
            self::CreateIssue => [
                ['name' => 'product_id', 'type' => 'product', 'required' => false],
                ['name' => 'project_id', 'type' => 'project', 'required' => false],
                ['name' => 'team_ids', 'type' => 'teams', 'required' => false],
                ['name' => 'title_template', 'type' => 'string', 'required' => false],
                ['name' => 'priority', 'type' => 'select', 'required' => false, 'options' => IssuePriority::options()],
                ['name' => 'owner_id', 'type' => 'user', 'required' => false],
                ['name' => 'label_ids', 'type' => 'labels', 'required' => false],
                ['name' => 'include_excerpt', 'type' => 'boolean', 'required' => false],
            ],
            self::LinkIssue => [
                ['name' => 'mode', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'identifier', 'label' => 'A fixed issue'],
                    ['value' => 'subject_match', 'label' => 'Identifier mentioned in the subject'],
                ]],
                ['name' => 'identifier', 'type' => 'string', 'required' => false],
            ],
            self::SetIssueStatus => [
                ['name' => 'status_id', 'type' => 'workflow_status', 'required' => true],
            ],
            self::SetIssuePriority => [
                ['name' => 'priority', 'type' => 'select', 'required' => true, 'options' => IssuePriority::options()],
            ],
            self::SetIssueOwner, self::AddIssueFollower => [
                ['name' => 'user_id', 'type' => 'user', 'required' => true],
            ],
            self::AddTeamToIssue => [
                ['name' => 'team_id', 'type' => 'team', 'required' => true],
                ['name' => 'is_required', 'type' => 'boolean', 'required' => false],
            ],
            self::AssignWorkstream => [
                ['name' => 'team_id', 'type' => 'team', 'required' => true],
                ['name' => 'user_id', 'type' => 'user', 'required' => false],
                ['name' => 'strategy', 'type' => 'select', 'required' => false, 'options' => [
                    ['value' => 'round_robin', 'label' => 'Round robin'],
                    ['value' => 'least_busy', 'label' => 'Least busy'],
                ]],
            ],
            self::SetWorkstreamStatus => [
                ['name' => 'team_id', 'type' => 'team', 'required' => true],
                ['name' => 'status_id', 'type' => 'workflow_status', 'required' => true],
            ],
            self::AddIssueLabel, self::RemoveIssueLabel => [
                ['name' => 'label_id', 'type' => 'label', 'required' => false],
                ['name' => 'label_name', 'type' => 'string', 'required' => false],
            ],
            self::AddIssueComment => [
                ['name' => 'body', 'type' => 'text', 'required' => true],
            ],
            self::MoveIssueToProject => [
                ['name' => 'project_id', 'type' => 'project', 'required' => true],
            ],
        };
    }

    /**
     * @return array<int, array{value: string, label: string, subjects: array<int, string>, params: array<int, array<string, mixed>>}>
     */
    public static function options(?AutomationSubjectType $subject = null): array
    {
        return collect(self::cases())
            ->filter(fn (self $action) => $subject === null || $action->supports($subject))
            ->map(fn (self $action) => [
                'value' => $action->value,
                'label' => $action->label(),
                'subjects' => array_map(fn (AutomationSubjectType $s) => $s->value, $action->subjectTypes()),
                'params' => $action->params(),
            ])
            ->values()
            ->all();
    }
}
