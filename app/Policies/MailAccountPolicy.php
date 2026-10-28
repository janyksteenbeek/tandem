<?php

namespace App\Policies;

use App\Models\MailAccount;
use App\Models\User;

class MailAccountPolicy
{
    public function view(User $user, MailAccount $account): bool
    {
        return $account->user_id === $user->id || $user->can('view', $account->inbox);
    }

    public function manage(User $user, MailAccount $account): bool
    {
        return $account->user_id === $user->id || $user->can('manage', $account->inbox);
    }

    public function update(User $user, MailAccount $account): bool
    {
        return $this->manage($user, $account);
    }

    public function delete(User $user, MailAccount $account): bool
    {
        return $this->manage($user, $account);
    }
}
