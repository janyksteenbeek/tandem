<?php

namespace App\Listeners\Calls;

use App\Events\Calls\CallEnded;
use App\Services\Calls\CallTranscripts;

/**
 * Sends the chat to everyone who was in the meeting, but only when the host switched that on
 * and there is something to send. The short delay gives a recording time to be finalised so
 * its link can be included.
 */
class EmailTranscriptWhenCallEnds
{
    public function __construct(protected CallTranscripts $transcripts) {}

    public function handle(CallEnded $event): void
    {
        $call = $event->call;

        if (! $call->setting('auto_email_transcript') || $call->message_count === 0) {
            return;
        }

        $recipients = $this->transcripts->attendeeRecipients($call);

        if ($recipients === []) {
            return;
        }

        $this->transcripts->email($call, $call->host, $recipients, automatic: true, delay: now()->addMinutes(2));
    }
}
