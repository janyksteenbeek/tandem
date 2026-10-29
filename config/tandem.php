<?php

use App\Services\Mail\Providers\Forwarding\ForwardingProvider;
use App\Services\Mail\Providers\Gmail\GmailProvider;
use App\Services\Mail\Providers\Imap\ImapProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Forwarding (Cloudflare Email Routing)
    |--------------------------------------------------------------------------
    |
    | Generated inbound addresses look like {local}@{domain}. The Cloudflare
    | Email Worker posts parsed messages to /webhooks/cloudflare/inbound and
    | authenticates with the shared secret below (HMAC-SHA256 signature).
    | Both the "Forward" and the "Google Group" channel use these addresses;
    | they only differ in the set-up instructions shown to the user.
    |
    */

    'forwarding' => [
        'domain' => env('TANDEM_FORWARDING_DOMAIN', 'forward.tandem.test'),
        'webhook_secret' => env('TANDEM_FORWARDING_WEBHOOK_SECRET'),
        'max_message_bytes' => (int) env('TANDEM_FORWARDING_MAX_BYTES', 25 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gmail
    |--------------------------------------------------------------------------
    */

    'gmail' => [
        'pubsub_token' => env('TANDEM_GMAIL_PUBSUB_TOKEN'),
        'scopes' => [
            'https://www.googleapis.com/auth/gmail.modify',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
        'watch_renew_before_hours' => 24,
        'initial_sync_days' => (int) env('TANDEM_GMAIL_INITIAL_SYNC_DAYS', 90),
        'reconcile_minutes' => (int) env('TANDEM_GMAIL_RECONCILE_MINUTES', 15),
        'sync_batch_size' => (int) env('TANDEM_GMAIL_SYNC_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | IMAP / SMTP
    |--------------------------------------------------------------------------
    */

    'imap' => [
        'poll_minutes' => (int) env('TANDEM_IMAP_POLL_MINUTES', 2),
        'initial_sync_days' => (int) env('TANDEM_IMAP_INITIAL_SYNC_DAYS', 90),
        'batch_size' => (int) env('TANDEM_IMAP_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    |
    | Mailer used for inboxes whose provider cannot send (forwarding
    | addresses) and for autoresponders when no account transport exists.
    |
    */

    'outbound' => [
        'mailer' => env('TANDEM_OUTBOUND_MAILER', env('MAIL_MAILER', 'log')),
        'max_attempts' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */

    'attachments' => [
        'disk' => env('TANDEM_ATTACHMENTS_DISK', 'local'),
        'max_upload_bytes' => (int) env('TANDEM_MAX_UPLOAD_BYTES', 25 * 1024 * 1024),
        'blocked_extensions' => ['exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'pif', 'js', 'jar', 'vbs', 'ps1', 'sh'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider adapters
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'gmail' => GmailProvider::class,
        'imap' => ImapProvider::class,
        'forwarding' => ForwardingProvider::class,
        'google_group' => ForwardingProvider::class,
    ],

    'snooze' => [
        'check_minutes' => 1,
    ],
];
