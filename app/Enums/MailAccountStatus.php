<?php

namespace App\Enums;

enum MailAccountStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Syncing = 'syncing';
    case Error = 'error';
    case NeedsReauth = 'needs_reauth';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Connected => 'Connected',
            self::Syncing => 'Syncing',
            self::Error => 'Error',
            self::NeedsReauth => 'Needs reconnect',
            self::Disconnected => 'Disconnected',
        };
    }

    public function isHealthy(): bool
    {
        return in_array($this, [self::Connected, self::Syncing], true);
    }
}
