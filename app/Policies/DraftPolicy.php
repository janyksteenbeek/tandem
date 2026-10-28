<?php

namespace App\Policies;

use App\Models\Draft;
use App\Models\User;

class DraftPolicy
{
    public function view(User $user, Draft $draft): bool
    {
        if ($draft->user_id === $user->id) {
            return true;
        }

        return $draft->is_shared && $user->can('act', $draft->inbox);
    }

    public function update(User $user, Draft $draft): bool
    {
        return $this->view($user, $draft) && ! $draft->isLockedByOther($user);
    }

    public function send(User $user, Draft $draft): bool
    {
        return $this->update($user, $draft) && $user->can('act', $draft->inbox);
    }

    public function delete(User $user, Draft $draft): bool
    {
        return $draft->user_id === $user->id || ($draft->is_shared && $user->can('act', $draft->inbox));
    }
}
