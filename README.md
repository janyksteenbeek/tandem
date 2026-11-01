<p align="center">
  <img src="public/tandem/tandem-mail-og.png" alt="Tandem Mail" width="720">
</p>

<p align="center">Calm mail for busy teams.</p>

---

Tandem is an email client for people and the teams they work in. Connect your own Gmail or IMAP accounts and use it as your everyday mail app, then run `support@` or `sales@` together in shared inboxes.

- **Personal mail** — multiple accounts, identities, threading, snooze, search, keyboard navigation.
- **Team inboxes** — assignments, Open / Waiting / Later / Done, tags, internal comments with mentions, presence.
- **Share or transfer** — move a personal conversation into a team inbox without touching your provider.
- **Providers** — Gmail API, IMAP/SMTP, and Cloudflare Email Routing for forwarded addresses.
- **Automations** — triggers, conditions, actions, templates, business hours and auto-responders.

## Quick start

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Needs PHP 8.4, MySQL and Redis. Sign in with `demo@tandem.test` / `password`.

For realtime, queues and scheduled sync, also run:

```bash
php artisan reverb:start
php artisan horizon
php artisan schedule:work
```

Or run the whole stack with `docker compose up --build`.

## Tests

```bash
php artisan test --compact
npm run types:check && npm test
```

## Docs

- [Architecture](docs/architecture.md)
- [Deployment](docs/deployment.md)
- [Automations](docs/automations.md)
- Providers: [Gmail](docs/providers/gmail.md) · [IMAP](docs/providers/imap.md) · [Cloudflare](docs/providers/cloudflare.md)

## License

MIT
