<?php

namespace App\Policies\Projects;

use App\Enums\Projects\IssueViewScope;
use App\Models\Projects\IssueView;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;

class IssueViewPolicy
{
    public function __construct(protected IssueAccess $access) {}

    public function view(User $user, IssueView $view): bool
    {
        return match ($view->scope) {
            IssueViewScope::Personal => $view->user_id === $user->id,
            IssueViewScope::Team => $view->team_id !== null && in_array($view->team_id, $this->access->teamIds($user, $view->organization_id), true),
            IssueViewScope::Organization => $this->access->isOrganizationMember($user, $view->organization_id),
        };
    }

    public function manage(User $user, IssueView $view): bool
    {
        if ($view->created_by_id === $user->id || $this->access->isOrganizationAdmin($user, $view->organization_id)) {
            return true;
        }

        return $view->scope === IssueViewScope::Team && $view->team !== null && $this->access->isTeamLead($user, $view->team);
    }
}
