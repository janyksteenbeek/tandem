<?php

namespace App\Jobs\Calls;

use App\Models\Organization;
use App\Services\Calls\CallMailer;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one call email (an invitation) through the organization's transport.
 */
class SendCallMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public Organization $organization, public string $email, public Mailable $mailable)
    {
        $this->onQueue('notifications');
    }

    public function handle(CallMailer $mailer): void
    {
        $mailer->sendNow($this->organization, $this->email, $this->mailable);
    }
}
