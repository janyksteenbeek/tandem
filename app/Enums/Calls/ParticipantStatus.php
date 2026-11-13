<?php

namespace App\Enums\Calls;

enum ParticipantStatus: string
{
    case Invited = 'invited';
    /** Asked to join and is waiting for a host to let them in. */
    case Waiting = 'waiting';
    /** Allowed in; holds (or may request) a media token but is not connected right now. */
    case Admitted = 'admitted';
    case Joined = 'joined';
    case Left = 'left';
    case Denied = 'denied';
    case Removed = 'removed';

    /**
     * Whether this person may fetch a media token and use the in-call endpoints.
     */
    public function isInside(): bool
    {
        return in_array($this, [self::Admitted, self::Joined, self::Left], true);
    }
}
