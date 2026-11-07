<?php

namespace App\Enums\Mail;

use App\Models\Mail\Inbox;

enum FolderType: string
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Trash = 'trash';
    case Spam = 'spam';
    case Archive = 'archive';
    case Starred = 'starred';
    case Important = 'important';
    case All = 'all';
    case Custom = 'custom';
}
