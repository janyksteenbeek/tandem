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

    /** Team-wide project updates: your team was added to an issue, a cycle started. */
    case Teams = 'teams';

    public function default(): bool
    {
        return $this !== self::Shared && $this !== self::Teams;
    }
}
