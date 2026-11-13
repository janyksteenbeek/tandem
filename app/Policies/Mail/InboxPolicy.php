<?php

namespace App\Policies\Mail;

use App\Enums\Mail\InboxRole;
use App\Models\Mail\Inbox;
use App\Models\User;

class InboxPolicy
{
    public function view(User $user, Inbox $inbox): bool
    {
        return $inbox->roleFor($user) !== null;
    }

    /**
     * Act on conversations: reply, assign, tag, comment.
     */
    public function act(User $user, Inbox $inbox): bool
    {
        return $inbox->roleFor($user)?->isAtLeast(InboxRole::Agent) ?? false;
    }

    /**
     * Manage the inbox itself: access, channels, forwarding addresses, automations.
     */
    public function manage(User $user, Inbox $inbox): bool
    {
        return $inbox->roleFor($user) === InboxRole::Admin;
    }

    public function update(User $user, Inbox $inbox): bool
    {
        return $this->manage($user, $inbox);
    }

    public function delete(User $user, Inbox $inbox): bool
    {
        return $this->manage($user, $inbox);
    }
}
