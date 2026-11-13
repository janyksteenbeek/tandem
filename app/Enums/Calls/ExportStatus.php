<?php

namespace App\Enums\Calls;

enum ExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
