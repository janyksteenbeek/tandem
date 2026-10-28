<?php

namespace App\Services\Mail\Providers\Imap;

use App\Models\MailAccount;
use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the Symfony SMTP transport for an IMAP/SMTP account. Swappable so tests can inject an
 * in-memory transport.
 */
class SmtpTransportFactory
{
    public function make(MailAccount $account): TransportInterface
    {
        $settings = (array) $account->setting('smtp', []);
        $settings['username'] ??= $account->setting('imap.username', $account->email_address);

        $password = $account->credential('smtp_password') ?? $account->credential('password');

        if (! is_string($password) || $password === '') {
            throw new AuthenticationRequiredException('No SMTP password is stored for this account.');
        }

        return $this->makeFromSettings($settings, $password);
    }

    /**
     * @param  array<string, mixed>  $settings  {host, port, encryption: ssl|tls|starttls|none, username}
     */
    public function makeFromSettings(array $settings, string $password): TransportInterface
    {
        $encryption = strtolower((string) ($settings['encryption'] ?? 'tls'));
        $implicitTls = in_array($encryption, ['ssl', 'tls'], true) && (int) ($settings['port'] ?? 0) === 465
            || $encryption === 'ssl';
        $port = (int) ($settings['port'] ?? ($implicitTls ? 465 : 587));

        $transport = new EsmtpTransport((string) ($settings['host'] ?? ''), $port, $implicitTls);

        if ($encryption === 'none') {
            $transport->setAutoTls(false);
        } else {
            $transport->setRequireTls(! $implicitTls);
        }

        $username = (string) ($settings['username'] ?? '');

        if ($username !== '') {
            $transport->setUsername($username)->setPassword($password);
        }

        return $transport;
    }
}
