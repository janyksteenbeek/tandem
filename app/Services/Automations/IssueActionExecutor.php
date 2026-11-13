<?php

namespace App\Services\Automations;

use App\Enums\Automations\AutomationActionType;
use App\Enums\Automations\AutomationTrigger;
use App\Enums\Projects\IssuePriority;
use App\Enums\Projects\StatusCategory;
use App\Exceptions\Projects\CompletionBlockedException;
use App\Models\Automations\AutomationRule;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueLabel;
use App\Models\Projects\Project;
use App\Models\Projects\WorkflowStatus;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AutomationNotification;
use App\Services\Projects\IssueActions;
use App\Services\Projects\IssueCommentService;
use App\Services\Projects\WorkflowActions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Executes issue actions through IssueActions with no actor and source "automation", registering
 * an echo for every domain event it causes so the engine never reacts to its own changes. The
 * completion rule is never bypassed: completing an issue with open required work is skipped.
 */
class IssueActionExecutor
{
    public function __construct(
        protected IssueActions $issues,
        protected WorkflowActions $workflows,
        protected IssueCommentService $comments,
        protected TriggerEchoGuard $echoes,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function execute(AutomationRule $rule, AutomationActionType $type, array $params, Issue $issue, array $context): array
    {
        try {
            return match ($type) {
                AutomationActionType::SetIssueStatus => $this->setStatus($issue, $params),
                AutomationActionType::SetIssuePriority => $this->setPriority($issue, $params),
                AutomationActionType::SetIssueOwner => $this->setOwner($issue, $params),
                AutomationActionType::AddTeamToIssue => $this->addTeam($issue, $params),
                AutomationActionType::AssignWorkstream => $this->assignWorkstream($issue, $params),
                AutomationActionType::SetWorkstreamStatus => $this->setWorkstreamStatus($issue, $params),
                AutomationActionType::AddIssueLabel => $this->toggleLabel($issue, $params, true),
                AutomationActionType::RemoveIssueLabel => $this->toggleLabel($issue, $params, false),
                AutomationActionType::AddIssueComment => $this->addComment($rule, $issue, $params),
                AutomationActionType::AddIssueFollower => $this->addFollower($issue, $params),
                AutomationActionType::MoveIssueToProject => $this->moveToProject($issue, $params),
                AutomationActionType::NotifyUsers => $this->notifyUsers($rule, $issue, $params),
                default => ['status' => 'skipped', 'reason' => 'not_applicable'],
            };
        } catch (ValidationException $e) {
            return ['status' => 'skipped', 'reason' => 'invalid', 'message' => Str::limit(collect($e->errors())->flatten()->first() ?? $e->getMessage(), 200)];
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function setStatus(Issue $issue, array $params): array
    {
        $status = $this->status($params['status_id'] ?? null);

        if (! $status) {
            return ['status' => 'skipped', 'reason' => 'status_not_found'];
        }

        if ($issue->status_id === $status->id) {
            return ['status' => 'skipped', 'reason' => 'unchanged', 'status_id' => $status->id];
        }

        $this->echoes->expect($issue, AutomationTrigger::OverallStatusChanged);

        if ($status->category === StatusCategory::Completed) {
            $this->echoes->expect($issue, AutomationTrigger::IssueCompleted);
        }

        try {
            $this->issues->setStatus($issue, $status, null, 'automation');
        } catch (CompletionBlockedException) {
            $this->echoes->consume($issue, AutomationTrigger::OverallStatusChanged);
            $this->echoes->consume($issue, AutomationTrigger::IssueCompleted);

            return ['status' => 'skipped', 'reason' => 'workstreams_incomplete', 'status_id' => $status->id];
        }

        return ['status' => 'done', 'status_id' => $status->id, 'status_name' => $status->name];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function setPriority(Issue $issue, array $params): array
    {
        $priority = IssuePriority::fromKey(is_string($params['priority'] ?? null) ? $params['priority'] : 'none');

        if ($issue->priority === $priority) {
            return ['status' => 'skipped', 'reason' => 'unchanged', 'priority' => $priority->key()];
        }

        $this->issues->setPriority($issue, $priority, null);

        return ['status' => 'done', 'priority' => $priority->key()];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function setOwner(Issue $issue, array $params): array
    {
        $user = $this->member($issue, $params['user_id'] ?? null);

        if (! $user) {
            return ['status' => 'skipped', 'reason' => 'user_not_found'];
        }

        if ($issue->owner_id === $user->id) {
            return ['status' => 'skipped', 'reason' => 'already_owner', 'user_id' => $user->id];
        }

        $this->issues->setOwner($issue, $user, null);

        return ['status' => 'done', 'user_id' => $user->id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function addTeam(Issue $issue, array $params): array
    {
        $team = $this->team($issue, $params['team_id'] ?? null);

        if (! $team) {
            return ['status' => 'skipped', 'reason' => 'team_not_found'];
        }

        if ($issue->workstreams()->where('team_id', $team->id)->exists()) {
            return ['status' => 'skipped', 'reason' => 'already_participating', 'team_id' => $team->id];
        }

        $this->echoes->expect($issue, AutomationTrigger::TeamAdded);
        $this->issues->addTeam($issue, $team, null, ['is_required' => (bool) ($params['is_required'] ?? true)]);

        return ['status' => 'done', 'team_id' => $team->id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function assignWorkstream(Issue $issue, array $params): array
    {
        $team = $this->team($issue, $params['team_id'] ?? null);

        if (! $team) {
            return ['status' => 'skipped', 'reason' => 'team_not_found'];
        }

        $workstream = $issue->workstreams()->where('team_id', $team->id)->first();

        if (! $workstream) {
            $this->echoes->expect($issue, AutomationTrigger::TeamAdded);
            $workstream = $this->issues->addTeam($issue, $team, null);
        }

        $user = is_numeric($params['user_id'] ?? null)
            ? $this->member($issue, $params['user_id'])
            : $this->pickMember($team, is_string($params['strategy'] ?? null) ? $params['strategy'] : 'round_robin');

        if (! $user) {
            return ['status' => 'skipped', 'reason' => 'user_not_found', 'team_id' => $team->id];
        }

        if ($workstream->assignees()->whereKey($user->id)->exists()) {
            return ['status' => 'skipped', 'reason' => 'already_assigned', 'user_id' => $user->id];
        }

        $this->echoes->expect($issue, AutomationTrigger::IssueAssigned);
        $this->issues->assign($workstream, $user, null);

        return ['status' => 'done', 'team_id' => $team->id, 'user_id' => $user->id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function setWorkstreamStatus(Issue $issue, array $params): array
    {
        $team = $this->team($issue, $params['team_id'] ?? null);
        $status = $this->status($params['status_id'] ?? null);

        if (! $team || ! $status) {
            return ['status' => 'skipped', 'reason' => 'team_or_status_not_found'];
        }

        $workstream = $issue->workstreams()->where('team_id', $team->id)->first();

        if (! $workstream) {
            return ['status' => 'skipped', 'reason' => 'team_not_participating', 'team_id' => $team->id];
        }

        if ($workstream->status_id === $status->id) {
            return ['status' => 'skipped', 'reason' => 'unchanged'];
        }

        $this->echoes->expect($issue, AutomationTrigger::WorkstreamStatusChanged);
        $this->issues->setWorkstreamStatus($workstream, $status, null, 'automation');

        return ['status' => 'done', 'team_id' => $team->id, 'status_id' => $status->id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function toggleLabel(Issue $issue, array $params, bool $attach): array
    {
        $query = IssueLabel::query()->where('organization_id', $issue->organization_id);

        $label = is_numeric($params['label_id'] ?? null) ? $query->whereKey((int) $params['label_id'])->first() : null;

        if (! $label && is_string($params['label_name'] ?? null) && trim($params['label_name']) !== '') {
            $name = trim($params['label_name']);
            $label = (clone $query)->where('scope_key', 'org')->where('slug', Str::slug($name))->first();

            if (! $label && $attach) {
                $label = IssueLabel::query()->create(['organization_id' => $issue->organization_id, 'name' => Str::limit($name, 60, ''), 'color' => 'slate']);
            }
        }

        if (! $label) {
            return ['status' => 'skipped', 'reason' => 'label_not_found'];
        }

        $has = $issue->labels()->whereKey($label->id)->exists();

        if ($attach === $has) {
            return ['status' => 'skipped', 'reason' => $attach ? 'already_labelled' : 'not_labelled', 'label_id' => $label->id];
        }

        $this->issues->toggleLabel($issue, $label, $attach, null);

        return ['status' => 'done', 'label_id' => $label->id, 'label' => $label->name];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function addComment(AutomationRule $rule, Issue $issue, array $params): array
    {
        $body = is_string($params['body'] ?? null) ? trim($params['body']) : '';

        if ($body === '') {
            return ['status' => 'skipped', 'reason' => 'empty_body'];
        }

        $author = $rule->created_by_id ? User::query()->whereKey($rule->created_by_id)->first() : null;
        $author ??= $issue->organization->owner();

        if (! $author instanceof User) {
            return ['status' => 'skipped', 'reason' => 'no_author'];
        }

        $rendered = str_replace(['{{ issue.identifier }}', '{{ issue.title }}', '{{ organization.name }}'], [$issue->identifier, e($issue->title), e($issue->organization->name)], $body);

        $this->echoes->expect($issue, AutomationTrigger::CommentAdded);
        $comment = $this->comments->post($issue, $author, str_starts_with($rendered, '<') ? $rendered : '<p>'.nl2br(e($rendered)).'</p>');

        return ['status' => 'done', 'comment_uuid' => $comment->uuid];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function addFollower(Issue $issue, array $params): array
    {
        $user = $this->member($issue, $params['user_id'] ?? null);

        if (! $user) {
            return ['status' => 'skipped', 'reason' => 'user_not_found'];
        }

        if ($issue->followers()->whereKey($user->id)->exists()) {
            return ['status' => 'skipped', 'reason' => 'already_following', 'user_id' => $user->id];
        }

        $this->issues->follow($issue, $user, true, null);

        return ['status' => 'done', 'user_id' => $user->id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function moveToProject(Issue $issue, array $params): array
    {
        $project = is_numeric($params['project_id'] ?? null) ? Project::query()->where('organization_id', $issue->organization_id)->whereKey((int) $params['project_id'])->first() : null;

        if (! $project) {
            return ['status' => 'skipped', 'reason' => 'project_not_found'];
        }

        if ($issue->project_id === $project->id) {
            return ['status' => 'skipped', 'reason' => 'already_in_project', 'project_id' => $project->id];
        }

        $this->issues->move($issue, $project->product_id ? $project->product : $issue->product, $project, null);

        return ['status' => 'done', 'project_id' => $project->id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function notifyUsers(AutomationRule $rule, Issue $issue, array $params): array
    {
        $ids = collect(is_array($params['user_ids'] ?? null) ? $params['user_ids'] : [])->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->values();
        $users = $ids->isEmpty() ? collect() : $issue->organization->members()->whereIn('users.id', $ids->all())->get();

        if ($users->isEmpty()) {
            return ['status' => 'skipped', 'reason' => 'no_recipients'];
        }

        $message = is_string($params['message'] ?? null) && trim($params['message']) !== '' ? Str::limit(trim($params['message']), 500) : null;

        Notification::send($users, new AutomationNotification($rule, $issue, $message));

        return ['status' => 'done', 'user_ids' => $users->pluck('id')->all()];
    }

    protected function status(mixed $id): ?WorkflowStatus
    {
        return is_numeric($id) ? WorkflowStatus::query()->whereKey((int) $id)->first() : null;
    }

    protected function team(Issue $issue, mixed $id): ?Team
    {
        return is_numeric($id) ? Team::query()->where('organization_id', $issue->organization_id)->whereKey((int) $id)->first() : null;
    }

    protected function member(Issue $issue, mixed $id): ?User
    {
        return is_numeric($id) ? $issue->organization->members()->whereKey((int) $id)->first() : null;
    }

    /**
     * Round robin through the team (cache counter) or the member with the fewest open workstreams.
     */
    protected function pickMember(Team $team, string $strategy): ?User
    {
        $members = $team->members()->orderBy('users.id')->get();

        if ($members->isEmpty()) {
            return null;
        }

        if ($strategy === 'least_busy') {
            return $members->sortBy(fn (User $user) => $user->workstreamAssignments()->whereNotIn('issue_workstreams.status_category', ['completed', 'canceled'])->count())->first();
        }

        $key = 'automations:issue-round-robin:'.$team->id;
        $index = (int) Cache::get($key, -1) + 1;
        Cache::put($key, $index, 86400 * 30);

        return $members[$index % $members->count()];
    }
}
