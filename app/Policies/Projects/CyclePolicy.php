<?php

namespace App\Policies\Projects;

use App\Models\Projects\Cycle;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;

class CyclePolicy
{
    public function __construct(protected IssueAccess $access) {}

    public function view(User $user, Cycle $cycle): bool
    {
        return $this->access->isOrganizationMember($user, $cycle->team->organization_id);
    }

    public function create(User $user, Team $team): bool
    {
        return $this->access->isTeamLead($user, $team) || $this->access->isOrganizationAdmin($user, $team->organization_id);
    }

    public function manage(User $user, Cycle $cycle): bool
    {
        return $this->create($user, $cycle->team);
    }
}
