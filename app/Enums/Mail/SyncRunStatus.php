<?php

namespace App\Enums\Mail;

enum SyncRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
