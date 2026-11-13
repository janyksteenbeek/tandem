<?php

namespace App\Services\Calls;

use App\Jobs\Calls\SendCallMail;
use App\Models\Organization;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Picks the transport for call emails the same way outbound mail does: the organization's own
 * SMTP server when it configured one, otherwise the application's outbound mailer. No
 * third-party delivery API is involved either way.
 */
class CallMailer
{
    /**
     * Queue a mailable for one recipient. The transport is chosen when the job runs, because
     * an organization's own SMTP server cannot be named in a queued mailable.
     */
    public function send(Organization $organization, string $email, Mailable $mailable): void
    {
        SendCallMail::dispatch($organization, $email, $mailable);
    }

    /**
     * Send right now (used inside jobs that track delivery themselves).
     */
    public function sendNow(Organization $organization, string $email, Mailable $mailable): void
    {
        $from = $this->fromFor($organization);

        if ($from !== null && method_exists($mailable, 'from')) {
            $mailable->from($from['address'], $from['name']);
        }

        $this->mailerFor($organization)->to($email)->sendNow($mailable);
    }

    public function mailerFor(Organization $organization): Mailer
    {
        $settings = $organization->outbound_mail_settings;

        if (is_array($settings) && ! empty($settings['host'])) {
            return Mail::build([
                'transport' => 'smtp',
                'scheme' => ($settings['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
                'host' => $settings['host'],
                'port' => (int) ($settings['port'] ?? 587),
                'username' => $settings['username'] ?? null,
                'password' => $settings['password'] ?? null,
                'timeout' => 15,
            ]);
        }

        return Mail::mailer(config('tandem.outbound.mailer'));
    }

    /**
     * The sender an organization's SMTP server expects, when it configured one.
     *
     * @return array{address: string, name: string}|null
     */
    protected function fromFor(Organization $organization): ?array
    {
        $settings = $organization->outbound_mail_settings;

        if (! is_array($settings) || empty($settings['host']) || empty($settings['from_name'])) {
            return null;
        }

        return ['address' => (string) config('mail.from.address'), 'name' => (string) $settings['from_name']];
    }
}
