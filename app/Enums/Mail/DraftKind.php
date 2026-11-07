<?php

namespace App\Enums\Mail;

enum DraftKind: string
{
    case New = 'new';
    case Reply = 'reply';
    case ReplyAll = 'reply_all';
    case Forward = 'forward';
}
