<?php

namespace App\Policies\Projects;

use App\Models\Projects\IssueComment;
use App\Models\User;

class IssueCommentPolicy
{
    public function update(User $user, IssueComment $comment): bool
    {
        return $comment->user_id === $user->id;
    }

    public function delete(User $user, IssueComment $comment): bool
    {
        return $comment->user_id === $user->id || $user->can('update', $comment->issue);
    }
}
