# Deploying Tandem

## Processes

| Process              | Command                                        | Notes                                                                                                                                                                                                          |
| -------------------- | ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Web                  | `php artisan octane:start --server=frankenphp` | Octane on FrankenPHP (port 8000). `APP_URL` must be the public HTTPS origin (OAuth redirect and webhooks derive from it). Set `TRUSTED_PROXIES`.                                                               |
| SSR                  | `php artisan inertia:start-ssr`                | Node 22 renders the first page load (port 13714). The web process reaches it through `INERTIA_SSR_URL`; failures fall back to client rendering.                                                                |
| Queues               | `php artisan horizon`                          | Supervisor consumes `mail-inbound, mail-outbound, mail-sync, broadcasts, notifications, automations, default`. Timeout is 300s for initial syncs. A second supervisor takes `calls-media` (recording exports). |
| Scheduler            | `php artisan schedule:run` every minute (cron) | Gmail watch renewal (hourly), Gmail reconcile (15 min), IMAP poll (1 min), snooze wake-ups (1 min), business-hours triggers (1 min), call sync (1 min).                                                        |
| Realtime             | `php artisan reverb:start`                     | Put behind TLS (`REVERB_SCHEME=https`), expose the port to browsers.                                                                                                                                           |
| IMAP IDLE (optional) | `php artisan mail:imap:idle`                   | One process; reconnects with backoff.                                                                                                                                                                          |
| Media (Calls)        | `tandem-media --config livekit.yaml`           | Go binary or its own image: the SFU and the recorder for meetings. Needs UDP from the internet; see [Calls](#calls). Optional: without it only meetings are unavailable.                                       |

## Environment

```
APP_URL=https://app.example.com
DB_CONNECTION=mysql …
REDIS_HOST=…
QUEUE_CONNECTION=redis  CACHE_STORE=redis  SESSION_DRIVER=redis
BROADCAST_CONNECTION=reverb  REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME   # + REVERB_PUBLIC_HOST/PORT/SCHEME when the browser reaches Reverb on another address
MAIL_MAILER=smtp …                       # application mail (invites, password resets)
TANDEM_OUTBOUND_MAILER=smtp               # replies from forwarding-only inboxes when the organization has no SMTP configured
TANDEM_FORWARDING_DOMAIN=forward.example.com
TANDEM_FORWARDING_WEBHOOK_SECRET=<random 64 chars>   # same value as the Worker secret
TANDEM_GMAIL_PUBSUB_TOKEN=<random>        # part of the Pub/Sub push URL
GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_PUBSUB_TOPIC   # optional global fallback; organizations can set their own
TANDEM_ATTACHMENTS_DISK=s3                # attachments and raw .eml files; needs AWS_ACCESS_KEY_ID/SECRET, AWS_DEFAULT_REGION, AWS_BUCKET (+ AWS_ENDPOINT, AWS_USE_PATH_STYLE_ENDPOINT=true for MinIO/R2)
SSO_GOOGLE_ENABLED=true                   # "Continue with Google" on the sign-in screens, see below
SSO_FORCE=false                           # true: identity providers are the only way in
LIVEKIT_* / CALLS_*                       # meetings, see Calls below
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
BRANDING_WORDMARK=/tandem/tandem-wordmark.svg
BRANDING_OG_IMAGE=/tandem/tandem-og.png
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

`Dockerfile` builds one image for every role; the role is the container command. The image is
host-agnostic: the browser gets Reverb's key and address from the server with the first page
(`REVERB_PUBLIC_*`, falling back to `REVERB_*`, and to the page's own hostname when the host is
empty), so nothing about the target host is compiled into the bundle. The CI workflow
`.github/workflows/docker.yml` publishes it to GitHub Container Registry as `ghcr.io/<owner>/<repo>`
tagged with the branch, the commit SHA, `latest` on `main` and the version for `v*` tags.
The same workflow publishes the media server for Calls from `media/tandem-media/Dockerfile` as
`ghcr.io/<owner>/<repo>-media` with the same tags, for `linux/amd64` and `linux/arm64`.

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

## Calls

Meetings need one process the rest of Tandem does not: `tandem-media`, a single Go binary with
the media server (LiveKit) and the recorder in it. Tandem works without it; only meetings are
unavailable. `docs/calls.md` explains how it works and what it costs to run. This section is the
checklist for putting it on a server.

### What the host needs

- **A public address that receives UDP.** Audio and video go straight from the browser to
  `tandem-media`, not through the web server. An HTTP-only platform or load balancer cannot
  carry it.
- **A hostname with TLS for signalling**, for example `meet.example.com`, proxied to port 7880
  with WebSocket support. Browsers refuse camera and microphone without HTTPS.
- **These ports open** on the firewall:

    | Port     | Purpose                                                       |
    | -------- | ------------------------------------------------------------- |
    | 443/tcp  | the app, Reverb and meeting signalling, through the TLS proxy |
    | 7882/udp | media; every participant shares this one port                 |
    | 7881/tcp | media, when a network blocks UDP                              |
    | 3478/udp | the built-in TURN relay                                       |

    Keep 7880 (signalling without TLS) and 7890 (recorder API) off the internet.

- **One recordings directory that both sides see.** `tandem-media` writes recordings into the
  application's `storage/app/private/calls/recordings`; the web and Horizon processes read,
  export and delete them there. Both run as uid 33 (`www-data`). Recordings stay on this local
  disk even when `TANDEM_ATTACHMENTS_DISK=s3`; files shared in a meeting follow that setting.
- **Horizon and the scheduler running**, as for the rest of Tandem. Horizon's `supervisor-media`
  builds recording exports with FFmpeg one at a time (the application image includes FFmpeg);
  the scheduler runs `calls:sync` every minute and `calls:prune-recordings` daily.

### With Docker Compose

1.  Point `app.example.com` and `meet.example.com` at the server and open the ports above.
2.  Add to `.env`:

    ```
    APP_DOMAIN=app.example.com
    MEET_DOMAIN=meet.example.com
    APP_URL=https://app.example.com
    REVERB_PUBLIC_HOST=app.example.com  REVERB_PUBLIC_PORT=443  REVERB_PUBLIC_SCHEME=https   # with the tls profile

    LIVEKIT_API_KEY=tandem                      # a name, not a secret
    LIVEKIT_API_SECRET=<openssl rand -hex 32>   # at least 32 characters
    LIVEKIT_PUBLIC_URL=wss://meet.example.com   # what browsers connect to
    CALLS_RECORDER_SECRET=<openssl rand -hex 32> # leave empty to run meetings without recording
    CALLS_RECORDING_RETENTION_DAYS=             # empty keeps recordings until someone deletes them
    ```

    `LIVEKIT_URL` and `CALLS_RECORDER_URL` are set by `compose.yaml` to the `media` service.

3.  Choose the images. To use the ones CI publishes instead of building on the server:

    ```
    TANDEM_IMAGE=ghcr.io/<owner>/<repo>:<version>
    TANDEM_MEDIA_IMAGE=ghcr.io/<owner>/<repo>-media:<version>
    ```

    Pin a release (`1.4.0` for the tag `v1.4.0`) or a commit (`sha-<full commit hash>`) rather
    than `latest`, and keep both images on the same one. Packages of a private repository
    need `docker login ghcr.io` with a token that may read packages.

4.  Start it. The `tls` profile adds Caddy, which gets certificates for both hostnames by
    itself (`media/caddy/Caddyfile`); leave the profile out when you already have a proxy and
    route `meet.example.com` to `127.0.0.1:7880` there.

    ```bash
    docker compose --profile tls pull
    docker compose --profile tls up -d --no-build
    ```

    Without the published images, `docker compose --profile tls up -d --build` builds both
    locally.

5.  Check it:

    ```bash
    docker compose ps media                                          # healthy
    docker compose exec media /usr/local/bin/tandem-media -version
    docker compose logs media | grep -i recorder                     # "recorder listening"
    ```

    Then start a meeting under Calls, join from a second device on another network (mobile
    data is a good test of the firewall), record for a few seconds and play it back.

### Without Docker

Build the binary on any machine with Go and copy it over; it is static and has no runtime
dependencies:

```bash
cd media/tandem-media
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -o tandem-media ./cmd/tandem-media
```

Copy `media/livekit/livekit.yaml` to `/etc/tandem/livekit.yaml` and fill in the key pair and
the webhook URL (`https://app.example.com/webhooks/calls/media`). Run it under systemd as the
user that runs the application:

```ini
# /etc/systemd/system/tandem-media.service
[Unit]
Description=Tandem media server
After=network-online.target
Wants=network-online.target

[Service]
User=www-data
Group=www-data
Environment=RECORDER_LISTEN=127.0.0.1:7890
Environment=RECORDINGS_DIR=/var/www/tandem/storage/app/private/calls/recordings
Environment=RECORDER_WEBHOOK_URL=https://app.example.com/webhooks/calls/recorder
# RECORDER_SECRET=<the same value as CALLS_RECORDER_SECRET>, in a file only root can read
EnvironmentFile=/etc/tandem/media.env
ExecStart=/usr/local/bin/tandem-media --config /etc/tandem/livekit.yaml
Restart=always
# Open recordings are closed and reported before the process exits.
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```

The application's `.env` then uses `LIVEKIT_URL=http://127.0.0.1:7880`,
`CALLS_RECORDER_URL=http://127.0.0.1:7890`, the same key pair and secret, and
`LIVEKIT_PUBLIC_URL=wss://meet.example.com`. Install FFmpeg on the host for exports, or set
`CALLS_FFMPEG_BINARY` to a static build.

### Upgrades and backups

- Restarting `tandem-media` ends the meetings running on it; recordings are closed first.
  Upgrade it when nobody is in a meeting: `GET /stats` on the recorder API, with the recorder
  secret as bearer token, lists what is being recorded.
- Recordings live in the storage volume under `private/calls/recordings`; include it in the
  backup next to the database, which holds the chat and the recording index.
- A recording that was cut off by a crash is repaired on the next start and reported as
  partial. `tandem-media -inspect <file>` shows what a track file contains and
  `-repair <file>` repairs one by hand.

### When something does not work

| Symptom                                            | Look at                                                                                                                                                                                               |
| -------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The meeting page cannot connect                    | `LIVEKIT_PUBLIC_URL` must be the `wss://` address browsers reach, and the proxy must pass WebSocket upgrades to port 7880.                                                                            |
| People join but nobody hears or sees anyone        | UDP 7882 and TCP 7881 closed, or the server advertises an address clients cannot reach. On a host whose public address is not on its interface set `rtc.node_ip` (see `media/livekit/livekit.yaml`).  |
| Meetings work, recording does not start            | `CALLS_RECORDER_SECRET` empty or different on the two sides, less free disk than `RECORDER_MIN_FREE_BYTES` (512 MiB), or the server cannot reach its own advertised address (same `rtc.node_ip` fix). |
| A recording plays, but exports stay queued or fail | Horizon's `supervisor-media` is not running, or FFmpeg is missing (`CALLS_FFMPEG_BINARY`).                                                                                                            |
| The app shows meetings as live after everyone left | The scheduler is not running (`calls:sync`), or the media webhook cannot reach `APP_URL/webhooks/calls/media`.                                                                                        |
