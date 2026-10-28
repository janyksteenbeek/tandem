<?php

namespace App\Enums;

enum DraftKind: string
{
    case New = 'new';
    case Reply = 'reply';
    case ReplyAll = 'reply_all';
    case Forward = 'forward';
}
