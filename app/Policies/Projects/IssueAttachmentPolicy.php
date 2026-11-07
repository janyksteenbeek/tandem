<?php

namespace App\Policies\Projects;

use App\Models\Projects\IssueAttachment;
use App\Models\User;

class IssueAttachmentPolicy
{
    public function view(User $user, IssueAttachment $attachment): bool
    {
        return $user->can('view', $attachment->issue);
    }

    public function delete(User $user, IssueAttachment $attachment): bool
    {
        return $attachment->uploaded_by_id === $user->id || $user->can('update', $attachment->issue);
    }
}
