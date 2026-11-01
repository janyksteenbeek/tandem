<?php

namespace App\Enums;

/**
 * The three switches a person has for push notifications on their devices.
 */
enum PushTopic: string
{
    /** Mentions, assignments and automations aimed at you. */
    case Direct = 'direct';

    /** Replies and comments on conversations you follow or own. */
    case Follows = 'follows';

    /** New conversations arriving in shared inboxes you belong to. */
    case Shared = 'shared';

    public function default(): bool
    {
        return $this !== self::Shared;
    }
}
