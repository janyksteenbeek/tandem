<?php

namespace App\Enums\Calls;

enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
