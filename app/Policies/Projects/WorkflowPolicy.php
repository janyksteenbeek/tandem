<?php

namespace App\Policies\Projects;

use App\Enums\Projects\ProjectAccessLevel;
use App\Enums\Projects\WorkflowKind;
use App\Models\Projects\Workflow;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;

class WorkflowPolicy
{
    public function __construct(protected IssueAccess $access) {}

    public function view(User $user, Workflow $workflow): bool
    {
        return $this->access->isOrganizationMember($user, $workflow->organization_id);
    }

    public function manage(User $user, Workflow $workflow): bool
    {
        if ($this->access->isOrganizationAdmin($user, $workflow->organization_id)) {
            return true;
        }

        if ($workflow->kind === WorkflowKind::Team && $workflow->team) {
            return $this->access->isTeamLead($user, $workflow->team);
        }

        if ($workflow->product) {
            return $this->access->productLevelFor($user, $workflow->product)?->isAtLeast(ProjectAccessLevel::Manager) ?? false;
        }

        return false;
    }
}
