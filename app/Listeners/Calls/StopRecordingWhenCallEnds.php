<?php

namespace App\Listeners\Calls;

use App\Events\Calls\CallEnded;
use App\Services\Calls\CallRecordings;

class StopRecordingWhenCallEnds
{
    public function __construct(protected CallRecordings $recordings) {}

    public function handle(CallEnded $event): void
    {
        $recording = $event->call->activeRecording;

        if ($recording !== null) {
            $this->recordings->stop($recording->setRelation('call', $event->call));
        }
    }
}
