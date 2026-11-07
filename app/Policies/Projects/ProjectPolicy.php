<?php

namespace App\Policies\Projects;

use App\Enums\Projects\ProjectAccessLevel;
use App\Models\Organization;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;

class ProjectPolicy
{
    public function __construct(protected IssueAccess $access) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->access->projectLevelFor($user, $project) !== null;
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->access->projectLevelFor($user, $project)?->isAtLeast(ProjectAccessLevel::Manager) ?? false;
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function setVisibility(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /**
     * Adding issues to a project: participants, or anyone when the project is organization-visible.
     */
    public function contribute(User $user, Project $project): bool
    {
        $level = $this->access->projectLevelFor($user, $project);

        return $level !== null && ($level->isAtLeast(ProjectAccessLevel::Participant) || ! $project->isPrivate());
    }

    public function delete(User $user, Project $project): bool
    {
        $level = $this->access->projectLevelFor($user, $project);

        return ($level?->isAtLeast(ProjectAccessLevel::Admin) ?? false) || $project->lead_id === $user->id;
    }
}
