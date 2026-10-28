<?php

namespace App\Enums;

enum SyncRunType: string
{
    case Initial = 'initial';
    case Incremental = 'incremental';
    case Push = 'push';
    case Reconcile = 'reconcile';
    case Idle = 'idle';
    case Watch = 'watch';
}
