<?php

namespace App\Enums\Calls;

enum RecordingStatus: string
{
    case Starting = 'starting';
    case Recording = 'recording';
    /** Stop was requested; the recorder is finalising the files. */
    case Processing = 'processing';
    case Ready = 'ready';
    /** Something went wrong, but the tracks captured until then are playable. */
    case Partial = 'partial';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Starting, self::Recording], true);
    }

    public function isPlayable(): bool
    {
        return in_array($this, [self::Ready, self::Partial], true);
    }
}
