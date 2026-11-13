<?php

namespace App\Enums\Calls;

enum CallStatus: string
{
    /** Created, nobody has joined yet. */
    case Pending = 'pending';
    case Live = 'live';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not started',
            self::Live => 'Live',
            self::Ended => 'Ended',
        };
    }
}
