# Deploying Tandem

## Processes

| Process              | Command                                        | Notes                                                                                                                                             |
| -------------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Web                  | `php artisan octane:start --server=frankenphp` | Octane on FrankenPHP (port 8000). `APP_URL` must be the public HTTPS origin (OAuth redirect and webhooks derive from it). Set `TRUSTED_PROXIES`.  |
| SSR                  | `php artisan inertia:start-ssr`                | Node 22 renders the first page load (port 13714). The web process reaches it through `INERTIA_SSR_URL`; failures fall back to client rendering.   |
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
TANDEM_ATTACHMENTS_DISK=s3                # attachments and raw .eml files; needs AWS_ACCESS_KEY_ID/SECRET, AWS_DEFAULT_REGION, AWS_BUCKET (+ AWS_ENDPOINT, AWS_USE_PATH_STYLE_ENDPOINT=true for MinIO/R2)
SSO_GOOGLE_ENABLED=true                   # "Continue with Google" on the sign-in screens, see below
SSO_FORCE=false                           # true: identity providers are the only way in
```

## Single sign-on

`config/sso.php` lists the identity providers. Google is built in and reuses `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` unless `SSO_GOOGLE_CLIENT_ID` / `SSO_GOOGLE_CLIENT_SECRET` are set, so one OAuth client can serve Gmail connections and sign-in. Register `https://app.example.com/auth/google/callback` as an authorised redirect URI on that client.

```
SSO_GOOGLE_ENABLED=true
SSO_GOOGLE_HOSTED_DOMAINS=example.com,example.org   # optional: only these Google Workspace domains may sign in
SSO_LINK_EXISTING_ACCOUNTS=true                      # link a first SSO sign-in to the user with the same verified email
SSO_FORCE=false
```

A first sign-in through a provider links the identity to the user whose email matches (when the provider reports it verified), or registers a new user when registration is open. Signed-in users connect and disconnect providers under Settings → Security, and can re-authenticate with a provider wherever a password confirmation is asked.

`SSO_FORCE=true` turns the providers into the only way in: password login, registration, password resets and changes, and passkey login are switched off and the sign-in screens only show the provider buttons. Forcing is ignored while no provider is enabled, so you cannot lock everyone out by accident. Make sure every existing user can reach a provider (their email matches, or they connected it beforehand) before turning it on.

## Branding

Everything the UI says about the product itself comes from `config/branding.php`: name, tagline, logo, favicon, the line on the sign-in panel and the brand colours. The common values have environment overrides; edit the config file for the rest.

```
BRANDING_NAME="Acme Mail"                # defaults to APP_NAME
BRANDING_TAGLINE="support, sorted"
BRANDING_LOGO=https://cdn.example.com/mark.svg          # omit to keep the built-in mark
BRANDING_LOGO_DARK=https://cdn.example.com/mark-dark.svg
BRANDING_FAVICON=/tandem/icon.svg
BRANDING_WORDMARK=/tandem/tandem-mail-wordmark-purple.svg
BRANDING_OG_IMAGE=/tandem/tandem-mail-og.png
BRANDING_AUTH_QUOTE="Inbox zero is a team sport."
BRANDING_COLOR_BRAND="#603df3"                           # any CSS colour; *_DARK variants apply in dark mode
BRANDING_COLOR_BRAND_FOREGROUND=
BRANDING_COLOR_BRAND_SOFT= BRANDING_COLOR_BRAND_SOFT_FOREGROUND=
BRANDING_COLOR_HIGHLIGHT= BRANDING_COLOR_HIGHLIGHT_FOREGROUND=
```

There is no public landing page: `/` sends guests to the login form and signed-in users into their mail.

## Push notifications

Device pushes use Web Push with VAPID keys. Generate a pair once per deployment and keep it stable; rotating it silently drops every subscribed device.

```
php artisan webpush:vapid        # writes VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY to .env
VAPID_SUBJECT="mailto:ops@example.com"
```

Without keys the Notifications settings page explains that push is not configured and nothing is sent. People opt in per device under Settings → Notifications; expired subscriptions are removed automatically when a push bounces. On iPhone and iPad pushes only arrive once the app is installed to the home screen.

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
npm ci && npm run build:ssr          # client bundle + bootstrap/ssr
php artisan migrate --force
php artisan optimize                 # config, routes, views, events
php artisan octane:reload            # workers pick up the new code
php artisan horizon:terminate        # queue workers restart under Supervisor
php artisan inertia:stop-ssr --graceful
```

## Kubernetes

`deploy/kubernetes/` holds Kustomize manifests for the Portivio cluster (web, ssr, horizon,
scheduler and reverb from one image, MariaDB through the operator, Valkey, Object Storage for
attachments, a Gateway API route and a cert-manager certificate). `deploy/kubernetes/README.md` walks
through building the image for the host, secrets and the first apply; `cluster/` holds the one-off
certificate, gateway listener and DNS steps.

## Docker

`Dockerfile` builds one image for every role; the role is the container command. The CI workflow
`.github/workflows/docker.yml` publishes it to GitHub Container Registry as `ghcr.io/<owner>/<repo>`
tagged with the branch, the commit SHA, `latest` on `main` and the version for `v*` tags.

| Role      | Command                                                             |
| --------- | ------------------------------------------------------------------- |
| web       | default: `php artisan octane:start --server=frankenphp --port=8000` |
| ssr       | `php artisan inertia:start-ssr`                                     |
| horizon   | `php artisan horizon`                                               |
| scheduler | `php artisan schedule:work`                                         |
| reverb    | `php artisan reverb:start --host=0.0.0.0 --port=8080`               |

`compose.yaml` wires these up with MariaDB and Redis and is the reference for any orchestrator:
every role shares the `.env`, `DB_HOST`/`REDIS_HOST` point at the services, `INERTIA_SSR_URL`
points at the `ssr` service, and `storage/app` is a shared volume when attachments stay on the
local disk (use `TANDEM_ATTACHMENTS_DISK=s3` to avoid that).

The image carries no `node_modules`: `vite.config.ts` sets `ssr.noExternal` so `bootstrap/ssr/app.js` inlines
every dependency and the `ssr` role only needs the Node binary.

The entrypoint runs `php artisan optimize` on every start (environment is only known then) and
`php artisan migrate --force` when `TANDEM_RUN_MIGRATIONS=true`; only the web container sets it.
The image runs as `www-data`, exposes port 8000 and reports health on `/up`. The FrankenPHP binary's
file capability (`CAP_NET_BIND_SERVICE`) is stripped in the image because a non-root process cannot exec
a capability-bearing binary on hosts that forbid privilege escalation (`no-new-privileges`,
`allowPrivilegeEscalation: false`); the symptom is `exec: /usr/local/bin/frankenphp: Operation not permitted`
in the web container's log. Keep the app on an unprivileged port (8000) and terminate TLS in front of it.

Base images are build arguments so a hardened mirror can be swapped in without touching the
Dockerfile. For Aikido Images set the repository variables `PHP_IMAGE` and `NODE_IMAGE` to the
paths shown under AutoFix → Containers → Aikido Images (`docker.aikido.io/<token>/dunglas/frankenphp:1-php8.4-bookworm`
and `docker.aikido.io/<token>/node:22-bookworm-slim`); the workflow falls back to the upstream
images when the variables are empty.
