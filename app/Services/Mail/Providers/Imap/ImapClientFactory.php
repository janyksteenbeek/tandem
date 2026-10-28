<?php

namespace App\Services\Mail\Providers\Imap;

use App\Models\MailAccount;
use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\IMAP;

/**
 * Builds authenticated ImapSession instances from account settings. Swap this in the container
 * (or mock it) to keep tests away from real mail servers.
 */
class ImapClientFactory
{
    public function __construct(protected int $timeoutSeconds = 30) {}

    public function make(MailAccount $account): ImapSession
    {
        $password = $account->credential('password');

        if (! is_string($password) || $password === '') {
            throw new AuthenticationRequiredException('No IMAP password is stored for this account.');
        }

        $settings = (array) $account->setting('imap', []);
        $settings['username'] ??= $account->email_address;

        return $this->makeFromSettings($settings, $password);
    }

    /**
     * @param  array<string, mixed>  $settings  {host, port, encryption: ssl|tls|starttls|none, username, validate_cert}
     */
    public function makeFromSettings(array $settings, string $password): ImapSession
    {
        $host = (string) ($settings['host'] ?? '');
        $encryption = strtolower((string) ($settings['encryption'] ?? 'ssl'));
        $port = (int) ($settings['port'] ?? ($encryption === 'starttls' || $encryption === 'none' ? 143 : 993));

        $config = Config::make([
            'default' => 'default',
            'options' => [
                'uid_cache' => false,
                'sequence' => IMAP::ST_UID,
                'fetch' => IMAP::FT_PEEK,
            ],
            'accounts' => [
                'default' => [
                    'host' => $host,
                    'port' => $port,
                    'protocol' => 'imap',
                    'encryption' => match ($encryption) {
                        'ssl', 'tls' => 'ssl',
                        'starttls' => 'starttls',
                        default => false,
                    },
                    'validate_cert' => (bool) ($settings['validate_cert'] ?? true),
                    'username' => (string) ($settings['username'] ?? ''),
                    'password' => $password,
                    'authentication' => null,
                    'timeout' => $this->timeoutSeconds,
                ],
            ],
        ]);

        return (new WebklexImapSession(new Client($config), $host, $port))->connect();
    }
}
