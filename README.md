# Tandem

Tandem is a calm, fast email client for people and the teams they work in. Connect your own Gmail or IMAP accounts and use Tandem as your everyday mail app, then run `support@`, `sales@` or `billing@` together with your organization in shared inboxes with assignments, internal comments, automations and real-time collaboration.

- **Personal email, first class** — Inbox, Starred, Later (snooze), Drafts, Sent, Archive, Spam, Trash; threading, rich-text compose with inline images and attachments, multiple accounts and sender identities, search across every account, keyboard-driven navigation, bulk actions.
- **Shared team inboxes** — assign, reassign, Open / Waiting / Later / Done, tags, priorities, followers, internal comments with @mentions, shared drafts, activity timeline, presence and collision detection, views for Unassigned / Assigned to me / All.
- **Personal → team collaboration** — explicitly _share_ a personal conversation with a team inbox (it stays in your mailbox) or _transfer_ it (the team inbox owns it). Neither touches your provider; the distinction is shown in the UI.
- **Providers** — Gmail API (OAuth per organization, history sync, Pub/Sub push with watch renewal and reconciliation), IMAP/SMTP (UID/UIDVALIDITY tracking, IDLE and polling, SMTP sending), Cloudflare Email Routing (Google Group and Forward channels on generated addresses, handled by a real Email Worker).
- **Automations** — a rule engine with triggers, conditions and actions, business hours, reusable templates with variables, auto-responders with cooldowns and loop prevention, and a full audit history.

## Stack

Laravel 13 · PHP 8.4 · Octane on FrankenPHP · MySQL · Redis · Horizon · Reverb · Inertia v3 with SSR · Vue 3 + TypeScript · shadcn-vue · Tailwind CSS 4 · Pest · Vitest · Playwright.

## Local development

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# MySQL: create databases `tandem` and `tandem_testing`; Redis must be running
php artisan migrate --seed            # seeds a demo workspace
npm run build                         # or `npm run dev`
php artisan serve                     # http://127.0.0.1:8000
php artisan reverb:start              # realtime (port 8080)
php artisan horizon                   # queues: mail-inbound, mail-outbound, mail-sync, broadcasts, notifications, automations
php artisan schedule:work             # watch renewal, IMAP polling, reconciliation, snooze wake-ups, business hours
```

Demo login: `demo@tandem.test` / `password` (organization _Northwind Labs_). The seeder pushes hand-written MIME through the real ingestion pipeline, so what you see is exactly how synced mail behaves.

Optional long-running process for instant IMAP delivery: `php artisan mail:imap:idle`.

The whole stack also runs in containers: `docker compose up --build` starts web (Octane), SSR, Horizon, scheduler, Reverb, MariaDB and Redis from the same image. See `docs/deployment.md`.

## Tests and checks

```bash
php artisan test --compact            # Pest (feature + unit)
vendor/bin/phpstan analyse            # Larastan level 7
vendor/bin/pint --dirty               # code style
npm run types:check && npm test       # vue-tsc + Vitest
npx playwright test                   # e2e against a running, seeded instance
```

## Documentation

- [Architecture](docs/architecture.md) — domain model, ingestion pipeline, sharing semantics, realtime, security.
- [Deployment](docs/deployment.md) — processes, queues, scheduler, environment variables, hardening.
- Providers: [Gmail](docs/providers/gmail.md) · [IMAP / SMTP](docs/providers/imap.md) · [Google Group & Forward via Cloudflare](docs/providers/cloudflare.md)
- [Automations](docs/automations.md) — rule JSON, variables, business hours, loop prevention.
- [Cloudflare Email Worker](cloudflare/email-worker/README.md)

## Status

Everything in this repository is tested locally with fixtures and fakes. The Gmail, IMAP and Cloudflare adapters are implemented end to end but have **not** been exercised against live Google, IMAP or Cloudflare accounts from this environment; follow the provider docs to connect real credentials and verify.
