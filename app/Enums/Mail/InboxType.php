<?php

namespace App\Enums\Mail;

enum InboxType: string
{
    case Personal = 'personal';
    case Shared = 'shared';
}
