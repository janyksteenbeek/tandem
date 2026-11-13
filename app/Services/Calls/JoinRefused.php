<?php

namespace App\Services\Calls;

use RuntimeException;

/**
 * A join request the call turned down. `reason` is a stable key the join screen explains.
 */
class JoinRefused extends RuntimeException
{
    public const ENDED = 'ended';

    public const LOCKED = 'locked';

    public const PASSWORD = 'password';

    public const FULL = 'full';

    public const REMOVED = 'removed';

    public function __construct(public string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function ended(): self
    {
        return new self(self::ENDED, 'This meeting has ended.');
    }

    public static function locked(): self
    {
        return new self(self::LOCKED, 'The host has locked this meeting.');
    }

    public static function password(): self
    {
        return new self(self::PASSWORD, 'That password is not correct.');
    }

    public static function full(): self
    {
        return new self(self::FULL, 'This meeting is full.');
    }

    public static function removed(): self
    {
        return new self(self::REMOVED, 'A host removed you from this meeting.');
    }
}
