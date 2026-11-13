<?php

namespace App\Policies\Projects;

use App\Enums\Projects\ProjectAccessLevel;
use App\Models\Organization;
use App\Models\Projects\Product;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;

class ProductPolicy
{
    public function __construct(protected IssueAccess $access) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->access->productLevelFor($user, $product) !== null;
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->access->isOrganizationAdmin($user, $organization->id);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->access->productLevelFor($user, $product)?->isAtLeast(ProjectAccessLevel::Manager) ?? false;
    }

    public function manageMembers(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }

    public function manageWorkflow(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->access->productLevelFor($user, $product)?->isAtLeast(ProjectAccessLevel::Admin) ?? false;
    }
}
