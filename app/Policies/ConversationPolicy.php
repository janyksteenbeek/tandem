<?php

namespace App\Policies;

use App\Enums\InboxRole;
use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->roleFor($user, $conversation) !== null;
    }

    public function act(User $user, Conversation $conversation): bool
    {
        return $this->roleFor($user, $conversation)?->isAtLeast(InboxRole::Agent) ?? false;
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $this->act($user, $conversation);
    }

    public function comment(User $user, Conversation $conversation): bool
    {
        return $this->act($user, $conversation);
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->act($user, $conversation);
    }

    /**
     * Only the owning inbox (not a shared-into inbox) may move or share the conversation onward.
     */
    public function share(User $user, Conversation $conversation): bool
    {
        return $conversation->inbox->roleFor($user)?->isAtLeast(InboxRole::Agent) ?? false;
    }

    public function move(User $user, Conversation $conversation): bool
    {
        return $this->share($user, $conversation);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $conversation->inbox->roleFor($user)?->isAtLeast(InboxRole::Agent) ?? false;
    }

    /**
     * Highest role the user has on the owning inbox or any inbox the conversation is shared into.
     */
    protected function roleFor(User $user, Conversation $conversation): ?InboxRole
    {
        $roles = collect([$conversation->inbox->roleFor($user)]);

        $conversation->loadMissing('shares.inbox');

        foreach ($conversation->shares as $share) {
            $roles->push($share->inbox->roleFor($user));
        }

        return $roles->filter()->sortByDesc(fn (InboxRole $role) => $role->level())->first();
    }
}
