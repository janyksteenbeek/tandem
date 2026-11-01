# Deploying Tandem

## Processes

| Process              | Command                                        | Notes                                                                                                                                             |
| -------------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Web                  | PHP-FPM / Octane serving `public/`             | `APP_URL` must be the public HTTPS origin (OAuth redirect and webhooks derive from it).                                                           |
| Queues               | `php artisan horizon`                          | Supervisor consumes `mail-inbound, mail-outbound, mail-sync, broadcasts, notifications, automations, default`. Timeout is 300s for initial syncs. |
| Scheduler            | `php artisan schedule:run` every minute (cron) | Gmail watch renewal (hourly), Gmail reconcile (15 min), IMAP poll (1 min), snooze wake-ups (1 min), business-hours triggers (1 min).              |
| Realtime             | `php artisan reverb:start`                     | Put behind TLS (`REVERB_SCHEME=https`), expose the port to browsers.                                                                              |
| IMAP IDLE (optional) | `php artisan mail:imap:idle`                   | One process; reconnects with backoff.                                                                                                             |

## Environment

```
APP_URL=https://app.example.com
DB_CONNECTION=mysql …
REDIS_HOST=…
QUEUE_CONNECTION=redis  CACHE_STORE=redis  SESSION_DRIVER=redis
BROADCAST_CONNECTION=reverb  REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME + VITE_REVERB_*
MAIL_MAILER=smtp …                       # application mail (invites, password resets)
TANDEM_OUTBOUND_MAILER=smtp               # replies from forwarding-only inboxes when the organization has no SMTP configured
TANDEM_FORWARDING_DOMAIN=forward.example.com
TANDEM_FORWARDING_WEBHOOK_SECRET=<random 64 chars>   # same value as the Worker secret
TANDEM_GMAIL_PUBSUB_TOKEN=<random>        # part of the Pub/Sub push URL
GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_PUBSUB_TOPIC   # optional global fallback; organizations can set their own
TANDEM_ATTACHMENTS_DISK=s3                # attachments and raw .eml files
```

## Branding

Everything the UI says about the product itself comes from `config/branding.php`: name, tagline, logo, favicon, the copy on the sign-in panel and the brand colours. The common values have environment overrides; edit the config file for the rest (for example the perks list).

```
BRANDING_NAME="Acme Mail"                # defaults to APP_NAME
BRANDING_TAGLINE="support, sorted"
BRANDING_LOGO=https://cdn.example.com/mark.svg          # omit to keep the built-in mark
BRANDING_LOGO_DARK=https://cdn.example.com/mark-dark.svg
BRANDING_FAVICON=/favicon.svg
BRANDING_AUTH_QUOTE="Inbox zero is a team sport."
BRANDING_AUTH_ATTRIBUTION="someone on the team"
BRANDING_AUTH_FOOTNOTE=
BRANDING_COLOR_BRAND="oklch(0.52 0.19 330)"             # any CSS colour; *_DARK variants apply in dark mode
BRANDING_COLOR_BRAND_FOREGROUND=
BRANDING_COLOR_BRAND_SOFT= BRANDING_COLOR_BRAND_SOFT_FOREGROUND=
BRANDING_COLOR_HIGHLIGHT= BRANDING_COLOR_HIGHLIGHT_FOREGROUND=
```

There is no public landing page: `/` sends guests to the login form and signed-in users into their mail.

## Provider setup

- Gmail: `docs/providers/gmail.md` (OAuth client per organization in Settings → Integrations, Pub/Sub topic and push subscription to `/webhooks/gmail/push/{TANDEM_GMAIL_PUBSUB_TOKEN}`).
- IMAP/SMTP: `docs/providers/imap.md`.
- Cloudflare forwarding: `docs/providers/cloudflare.md` and `cloudflare/email-worker/README.md` (deploy the Worker with `wrangler deploy`, set `TANDEM_INGEST_URL=https://app.example.com/webhooks/cloudflare/inbound` and the shared secret).

## Hardening checklist

- `APP_DEBUG=false`, `APP_ENV=production` (destructive DB commands are prohibited automatically).
- Terminate TLS in front of the app and Reverb; set `SESSION_SECURE_COOKIE=true`.
- Restrict `/horizon` (gate allows organization owners/admins) at the network level as well.
- Rotate `TANDEM_FORWARDING_WEBHOOK_SECRET` and `TANDEM_GMAIL_PUBSUB_TOKEN` by updating both sides.
- Back up MySQL and the attachments disk together; `mail/{conversation}/{message}/raw.eml` lets you re-ingest if ever needed.

## Build

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan horizon:terminate   # after each deploy so workers reload code
```
