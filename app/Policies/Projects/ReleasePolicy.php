<?php

namespace App\Policies\Projects;

use App\Models\Projects\Release;
use App\Models\User;

class ReleasePolicy
{
    public function view(User $user, Release $release): bool
    {
        return $user->can('view', $release->product);
    }

    public function update(User $user, Release $release): bool
    {
        return $user->can('update', $release->product);
    }

    public function delete(User $user, Release $release): bool
    {
        return $this->update($user, $release);
    }
}
