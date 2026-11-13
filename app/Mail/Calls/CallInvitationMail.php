<?php

namespace App\Mail\Calls;

use App\Models\Calls\Call;
use App\Models\User;
use App\Services\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CallInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Call $call, public User $inviter, public string $joinUrl, public ?string $note = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->inviter->name.' invited you to a meeting: '.$this->call->displayTitle());
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.calls.invitation',
            with: [
                'title' => $this->call->displayTitle(),
                // Not `inviter`: public properties of a mailable shadow view data of the same name.
                'inviterName' => $this->inviter->name,
                'organization' => $this->call->organization->name,
                'joinUrl' => $this->joinUrl,
                'note' => $this->note,
                'hasPassword' => $this->call->hasPassword(),
                'brand' => app(Branding::class)->name(),
            ],
        );
    }
}
