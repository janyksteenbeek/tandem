<?php

namespace App\Enums;

enum ProviderCapability: string
{
    case Send = 'send';
    case Push = 'push';
    case Idle = 'idle';
    case Labels = 'labels';
    case Folders = 'folders';
    case Threads = 'threads';
    case Drafts = 'drafts';
    case Flags = 'flags';
    case Archive = 'archive';
    case Trash = 'trash';
    case Spam = 'spam';
}
