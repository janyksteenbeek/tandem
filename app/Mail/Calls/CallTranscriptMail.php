<?php

namespace App\Mail\Calls;

use App\Models\Calls\Call;
use App\Services\Branding;
use App\Services\Calls\CallTranscripts;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The chat of a finished meeting, as HTML and as plain text.
 */
class CallTranscriptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Call $call, public bool $includeFiles = true, public bool $includeRecording = true) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Meeting chat: '.$this->call->displayTitle());
    }

    public function content(): Content
    {
        $transcripts = app(CallTranscripts::class);

        return new Content(
            view: 'mail.calls.transcript',
            text: 'mail.calls.transcript-text',
            with: [
                'transcript' => $transcripts->build($this->call, $this->includeFiles, $this->includeRecording),
                'text' => $transcripts->text($this->call, $this->includeFiles, $this->includeRecording),
                'brand' => app(Branding::class)->name(),
            ],
        );
    }
}
