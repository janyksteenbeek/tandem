<?php

namespace App\Policies\Projects;

use App\Enums\Projects\ProjectAccessLevel;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;

/**
 * Global issue properties need Manager level (owner, lead, product owner, admin). Workstream
 * properties only need to be on that workstream's team. Anyone who can see an issue can comment.
 */
class IssuePolicy
{
    public function __construct(protected IssueAccess $access) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function view(User $user, Issue $issue): bool
    {
        return $this->access->levelFor($user, $issue) !== null;
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function update(User $user, Issue $issue): bool
    {
        return $this->manager($user, $issue);
    }

    public function setStatus(User $user, Issue $issue): bool
    {
        return $this->manager($user, $issue);
    }

    public function overrideCompletion(User $user, Issue $issue): bool
    {
        return $this->manager($user, $issue);
    }

    public function addTeam(User $user, Issue $issue): bool
    {
        return $this->manager($user, $issue);
    }

    public function removeTeam(User $user, Issue $issue): bool
    {
        return $this->manager($user, $issue);
    }

    public function setWorkstreamRequired(User $user, Issue $issue): bool
    {
        return $this->manager($user, $issue);
    }

    /**
     * Status, assignees, responsibility, estimate, due date and cycle of one team's workstream.
     */
    public function updateWorkstream(User $user, Issue $issue, IssueWorkstream $workstream): bool
    {
        return $this->access->workstreamLevelFor($user, $workstream)?->isAtLeast(ProjectAccessLevel::Participant) ?? false;
    }

    public function comment(User $user, Issue $issue): bool
    {
        return $this->view($user, $issue);
    }

    public function attach(User $user, Issue $issue): bool
    {
        return $this->view($user, $issue);
    }

    public function follow(User $user, Issue $issue): bool
    {
        return $this->view($user, $issue);
    }

    public function move(User $user, Issue $issue): bool
    {
        return $this->manager($user, $issue);
    }

    public function linkConversation(User $user, Issue $issue): bool
    {
        return $this->access->levelFor($user, $issue)?->isAtLeast(ProjectAccessLevel::Participant) ?? false;
    }

    public function shareContent(User $user, Issue $issue): bool
    {
        return $this->linkConversation($user, $issue);
    }

    public function delete(User $user, Issue $issue): bool
    {
        return $this->manager($user, $issue);
    }

    public function restore(User $user, Issue $issue): bool
    {
        return $this->manager($user, $issue);
    }

    protected function manager(User $user, Issue $issue): bool
    {
        return $this->access->levelFor($user, $issue)?->isAtLeast(ProjectAccessLevel::Manager) ?? false;
    }
}
