<p align="center">
  <img src="public/tandem/tandem-mail-og.png" alt="Tandem Mail" width="720">
</p>

<p align="center">Calm mail for busy teams.</p>

<p align="center">
  <a href="https://github.com/janyksteenbeek/tandem/actions/workflows/tests.yml"><img src="https://github.com/janyksteenbeek/tandem/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://github.com/janyksteenbeek/tandem/actions/workflows/docker.yml"><img src="https://github.com/janyksteenbeek/tandem/actions/workflows/docker.yml/badge.svg" alt="Docker"></a>
  <a href="https://github.com/janyksteenbeek/tandem/releases"><img src="https://img.shields.io/github/v/release/janyksteenbeek/tandem" alt="GitHub release (latest by date)"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/janyksteenbeek/tandem" alt="License"></a>
  <a href="https://github.com/janyksteenbeek/tandem/issues"><img src="https://img.shields.io/github/issues/janyksteenbeek/tandem" alt="GitHub issues"></a>
</p>

---

Tandem is an email client for people and the teams they work in. Connect your own Gmail or IMAP accounts and use it as your everyday mail app, then run `support@` or `sales@` together in shared inboxes. Free and public source.

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

Tandem is released under the Creative Commons Attribution-NonCommercial 4.0 International license. See
the [LICENSE](LICENSE). A human friendly summary is available
at [creativecommons.org](https://creativecommons.org/licenses/by-nc/4.0/). An addendum regarding clarification of
non-commercial use is available at [NON-COMMERCIAL.md](NON-COMMERCIAL.md).

Dependencies may be subject to their own licenses.

## Security

If you discover any security-related issues, please email [tandem@janyk.dev](mailto:tandem@janyk.dev) instead of using
the issue tracker. All security vulnerabilities will be promptly addressed.
