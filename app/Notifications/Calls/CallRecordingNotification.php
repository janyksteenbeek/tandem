<?php

namespace App\Notifications\Calls;

use App\Models\Calls\CallRecording;

/**
 * Tells the host how a recording ended: ready, partly captured, or failed. A failed recording
 * is never left for someone to discover later.
 */
class CallRecordingNotification extends CallNotification
{
    /**
     * @param  'ready'|'partial'|'failed'  $outcome
     */
    public function __construct(public CallRecording $recording, public string $outcome)
    {
        parent::__construct($recording->call);
    }

    protected function kind(): string
    {
        return 'call_recording_'.$this->outcome;
    }

    protected function title(): string
    {
        return match ($this->outcome) {
            'ready' => 'Your recording is ready',
            'partial' => 'Your recording was only partly captured',
            default => 'Recording failed',
        };
    }

    protected function body(): ?string
    {
        $title = $this->call->displayTitle();

        return $this->outcome === 'failed' && $this->recording->failure_reason
            ? $title.' · '.$this->recording->failure_reason
            : $title;
    }

    protected function url(): string
    {
        $parameters = ['current_organization' => $this->call->organization->slug, 'call' => $this->call->uuid];

        return $this->outcome === 'failed'
            ? route('calls.show', $parameters)
            : route('calls.recordings.show', [...$parameters, 'recording' => $this->recording->uuid]);
    }
}
