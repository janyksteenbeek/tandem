<?php

namespace App\Policies\Calls;

use App\Models\Calls\Call;
use App\Models\Organization;
use App\Models\User;

/**
 * Who may see and manage a call's history from inside the organization. What happens inside a
 * running meeting is decided per participant (ParticipantAbilities), because guests have no user.
 */
class CallPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    /**
     * History, chat, files and recordings: the host, organization members who took part, and
     * organization administrators. Guests never reach this; they have no organization.
     */
    public function view(User $user, Call $call): bool
    {
        if (! $user->belongsToOrganization($call->organization)) {
            return false;
        }

        return $this->update($user, $call)
            || $call->participants()->where('user_id', $user->id)->whereNotNull('admitted_at')->exists();
    }

    public function update(User $user, Call $call): bool
    {
        return $call->host_id === $user->id
            || in_array($call->organization_id, $user->administeredOrganizationIds(), true);
    }

    public function delete(User $user, Call $call): bool
    {
        return $this->update($user, $call);
    }

    /** Send or resend the transcript, share a recording link, build exports. */
    public function share(User $user, Call $call): bool
    {
        return $this->view($user, $call);
    }

    public function deleteRecordings(User $user, Call $call): bool
    {
        return $this->update($user, $call);
    }
}
