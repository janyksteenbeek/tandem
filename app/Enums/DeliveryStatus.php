<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case None = 'none';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Bounced = 'bounced';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
