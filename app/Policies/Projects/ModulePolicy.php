<?php

namespace App\Policies\Projects;

use App\Enums\Projects\ProjectAccessLevel;
use App\Models\Projects\Module;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;

class ModulePolicy
{
    public function __construct(protected IssueAccess $access) {}

    public function view(User $user, Module $module): bool
    {
        return $this->access->productLevelFor($user, $module->product) !== null;
    }

    public function update(User $user, Module $module): bool
    {
        return $this->access->productLevelFor($user, $module->product)?->isAtLeast(ProjectAccessLevel::Manager) ?? false;
    }

    public function delete(User $user, Module $module): bool
    {
        return $this->update($user, $module);
    }
}
