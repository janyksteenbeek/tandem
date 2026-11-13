<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToOrganization($team->organization);
    }

    public function manage(User $user, Team $team): bool
    {
        return $user->organizationRole($team->organization)?->isAtLeast(OrganizationRole::Admin) ?? false;
    }

    /**
     * Team leads (and organization admins) run the team's workflow, cycles and views.
     */
    public function lead(User $user, Team $team): bool
    {
        return $this->manage($user, $team) || $team->roleOf($user) === TeamRole::Lead;
    }

    public function update(User $user, Team $team): bool
    {
        return $this->manage($user, $team);
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->manage($user, $team);
    }
}
