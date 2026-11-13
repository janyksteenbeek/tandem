<?php

namespace App\Policies\Projects;

use App\Models\Organization;
use App\Models\Projects\Initiative;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;

class InitiativePolicy
{
    public function __construct(protected IssueAccess $access) {}

    public function view(User $user, Initiative $initiative): bool
    {
        return $this->access->isOrganizationMember($user, $initiative->organization_id);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->access->isOrganizationAdmin($user, $organization->id);
    }

    public function update(User $user, Initiative $initiative): bool
    {
        return $initiative->owner_id === $user->id || $this->access->isOrganizationAdmin($user, $initiative->organization_id);
    }

    public function delete(User $user, Initiative $initiative): bool
    {
        return $this->update($user, $initiative);
    }
}
