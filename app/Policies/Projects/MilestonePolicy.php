<?php

namespace App\Policies\Projects;

use App\Models\Projects\Milestone;
use App\Models\User;

class MilestonePolicy
{
    public function view(User $user, Milestone $milestone): bool
    {
        return $milestone->project ? $user->can('view', $milestone->project) : ($milestone->initiative ? $user->can('view', $milestone->initiative) : false);
    }

    public function update(User $user, Milestone $milestone): bool
    {
        return $milestone->project ? $user->can('update', $milestone->project) : ($milestone->initiative ? $user->can('update', $milestone->initiative) : false);
    }

    public function delete(User $user, Milestone $milestone): bool
    {
        return $this->update($user, $milestone);
    }
}
