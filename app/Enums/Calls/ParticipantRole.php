<?php

namespace App\Enums\Calls;

enum ParticipantRole: string
{
    case Host = 'host';
    /** A member of the call's organization. */
    case Member = 'member';
    /** Anyone else: no Tandem account, or an account outside the organization. */
    case Guest = 'guest';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isHost(): bool
    {
        return $this === self::Host;
    }
}
