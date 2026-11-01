# Google Group and Forward channels via Cloudflare Email Routing

Tandem can receive mail for a shared inbox without any mailbox credentials. Two channels work this
way and share one mechanism:

| Channel          | `mail_accounts.provider` | Meant for                                                                                                         |
| ---------------- | ------------------------ | ----------------------------------------------------------------------------------------------------------------- |
| **Google Group** | `google_group`           | Teams that already answer from a Google Workspace group: the generated address is added to the group as a member. |
| **Forward**      | `forwarding`             | Any other address, alias or distribution list: a forwarding rule sends a copy to the generated address.           |

Both channels generate a unique inbound address on a domain Tandem controls (for example
`x7k2m9qp4a@forward.tandem.com`), are backed by the same `ForwardingProvider` adapter, and are
ingested by the same webhook and job. They differ only in the label, icon and set-up steps shown to
the user. Every message that reaches the address shows up in the inbox within seconds.

Replies from such inboxes are sent through the organization's SMTP settings or the application
mailer (`FallbackMailer`), never through Cloudflare.

## Architecture

```
 customer@example.com
        |
        v
 support@customer.com  (Google Group / M365 DL / mailbox forwarding rule)
        |
        v   SMTP
 Cloudflare Email Routing (MX for forward.tandem.com, catch-all -> Worker)
        |
        v   email() event, raw MIME stream
 cloudflare/email-worker  (tandem-email-worker)
        |   base64(raw) + envelope, signed with X-Tandem-Signature (HMAC-SHA256)
        v   HTTPS POST
 POST /webhooks/cloudflare/inbound      VerifyCloudflareWorkerSignature -> CloudflareInboundController
        |   1. validate + size check
        |   2. WebhookReceipt::claim('cloudflare', id)   (exactly-once)
        |   3. resolve MailAccount (provider in [forwarding, google_group], email=normalized recipient)
        |   4. write storage/app/inbound/{uuid}.eml
        |   5. dispatch IngestForwardedEmail  (queue: mail-inbound)
        v
 IngestForwardedEmail job
        |   loop prevention -> MimeMessageParser -> MessageIngestor
        v
 Conversation + Message in the mapped Inbox, temp .eml deleted
```

Components:

| Piece              | Location                                                                                                                                                |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Worker             | `cloudflare/email-worker/`                                                                                                                              |
| Signature scheme   | `app/Services/Mail/Providers/Forwarding/WebhookSignature.php`, `cloudflare/email-worker/src/signature.js`                                               |
| Webhook            | `app/Http/Middleware/VerifyCloudflareWorkerSignature.php`, `app/Http/Controllers/Webhooks/CloudflareInboundController.php`                              |
| Ingestion          | `app/Jobs/Mail/IngestForwardedEmail.php`                                                                                                                |
| Address generation | `app/Services/Mail/Providers/Forwarding/ForwardingAddressGenerator.php`                                                                                 |
| Provider adapter   | `app/Services/Mail/Providers/Forwarding/ForwardingProvider.php` (push-only, registered for both `forwarding` and `google_group` in `config/tandem.php`) |
| Management         | `app/Http/Controllers/Mail/ForwardingAddressController.php`, routes `mail.forwarding.*`                                                                 |
| Dev tooling        | `php artisan mail:forwarding:simulate`                                                                                                                  |

## Tandem configuration

```dotenv
TANDEM_FORWARDING_DOMAIN=forward.tandem.com
TANDEM_FORWARDING_WEBHOOK_SECRET=<64+ random hex characters>
TANDEM_FORWARDING_MAX_BYTES=26214400
```

Generate the secret with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. The same value is
stored in the Worker as the `TANDEM_WEBHOOK_SECRET` secret.

Make sure a queue worker (Horizon) consumes the `mail-inbound` queue.

## DNS setup

1. Pick a dedicated (sub)domain, for example `forward.tandem.com`. Do not reuse a domain that
   already receives mail elsewhere; Cloudflare Email Routing takes over its MX records.
2. In the Cloudflare dashboard open the zone -> **Email** -> **Email Routing** -> **Get started**
   and let Cloudflare add the records. For a sub-domain they look like:

    | Type | Name      | Content                                      | Priority |
    | ---- | --------- | -------------------------------------------- | -------- |
    | MX   | `forward` | `route1.mx.cloudflare.net`                   | 69       |
    | MX   | `forward` | `route2.mx.cloudflare.net`                   | 15       |
    | MX   | `forward` | `route3.mx.cloudflare.net`                   | 6        |
    | TXT  | `forward` | `v=spf1 include:_spf.mx.cloudflare.net ~all` |          |

3. Optionally add a `_dmarc.forward` TXT record (`v=DMARC1; p=none;`) so reports reach you.

## Deploying the Worker

```sh
cd cloudflare/email-worker
npm install
npx wrangler login
# edit wrangler.toml: TANDEM_INGEST_URL = "https://app.tandem.com/webhooks/cloudflare/inbound"
npm run deploy
npx wrangler secret put TANDEM_WEBHOOK_SECRET      # paste TANDEM_FORWARDING_WEBHOOK_SECRET
```

Then in **Email Routing -> Routing rules** set the **Catch-all address** action to
**Send to a Worker** and choose `tandem-email-worker`. Every address on the domain now reaches the
Worker; Tandem decides which inbox (if any) it belongs to.

Variables and secrets:

| Name                    | Where                 | Purpose                                    |
| ----------------------- | --------------------- | ------------------------------------------ |
| `TANDEM_INGEST_URL`     | `[vars]`              | Webhook URL                                |
| `TANDEM_WEBHOOK_SECRET` | `wrangler secret put` | HMAC secret shared with Tandem             |
| `MAX_BYTES`             | `[vars]`              | Reject oversize messages before upload     |
| `FALLBACK_FORWARD_TO`   | `[vars]`, optional    | Verified address that also receives a copy |

Use `npx wrangler tail` to follow live logs while testing.

## Adding a channel in the UI

1. Open **Settings** -> **Mail accounts** and find the shared inbox (requires the inbox _Admin_
   role; organization owners and admins always have it).
2. Click **Add channel** and pick **Google Group** or **Forward** (the same dialog also offers
   Gmail and IMAP). Optionally give the channel a label.
3. Click **Generate address**. Tandem creates a `MailAccount` with `provider = google_group` or
   `forwarding`, a unique 10-character address and status _Connected_, and shows the address with
   channel-specific set-up steps.
4. Copy the address and configure the source (below).
5. Click **Send test email**: Tandem emails a short "Tandem forwarding test" message to the address
   through the normal outbound mailer. When it appears in the inbox the whole chain works.
   `sync_state.last_test_sent_at` records when the test was sent.
6. **Remove** soft-deletes the account. Mail sent to the address afterwards is answered with
   `unknown_recipient` and dropped.

Routes: `POST mail/inboxes/{inbox}/forwarding-addresses` (`mail.forwarding.store`, body
`channel=google_group|forwarding`, default `forwarding`),
`DELETE mail/forwarding-addresses/{account}` (`mail.forwarding.destroy`),
`POST mail/forwarding-addresses/{account}/test` (`mail.forwarding.test`).

Addresses accept `+tag` sub-addressing: `x7k2m9qp4a+billing@forward.tandem.com` is delivered to the
same inbox as `x7k2m9qp4a@forward.tandem.com`. Matching is case-insensitive.

### Google Group channel (Google Workspace)

1. Google Admin console -> **Directory** -> **Groups** -> open the group (e.g. `support@customer.com`).
2. **Members** -> **Add members** -> enter the Tandem forwarding address -> add it as a _Member_.
3. **Access settings**: allow _External_ members, and allow _Anyone on the web_ (or at least
   _External_) to **Publish posts** so customers outside the organization can email the group.
4. Google sends the member a confirmation email; Tandem ingests it into the inbox, so there is
   nothing to click. If the group has moderation enabled, approve the address once.
5. Optional: **Group settings -> Email options -> Subject prefix** should be empty to keep
   subjects clean.

Google rewrites the envelope sender and adds `List-Id`/`Precedence: list` headers. Tandem keeps
those messages: they are only skipped when they are auto-submitted **and** addressed solely to the
forwarding address (see loop prevention).

### Forward channel: Microsoft 365 distribution list

1. Exchange admin center -> **Recipients** -> **Contacts** -> **Add a mail contact** with the Tandem
   forwarding address.
2. **Recipients** -> **Groups** -> open the distribution list -> **Members** -> add the contact.
3. On the list's **Settings** enable _Allow external senders_ ("Allow people outside my
   organization to send to this group").
4. Alternatively, for a single mailbox: **Recipients -> Mailboxes -> mailbox -> Manage mail flow
   settings -> Email forwarding** -> forward to the mail contact and keep a copy if desired.

### Forward channel: other mailboxes

Any forwarding rule (Gmail _Forward a copy of incoming mail to_, Zoho, Fastmail, a cPanel
forwarder, etc.) works. Gmail requires confirming the forwarding address: the confirmation email
lands in the Tandem inbox, open it and click the link.

## Loop prevention

Forwarding chains can bounce mail around indefinitely. `IngestForwardedEmail` drops a message
(receipt status `skipped`, reason in `webhook_receipts.payload.skipped_reason`) when:

| Rule                                                                                                                                                                                             | Reason code                            |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------- |
| The Worker counted more than 3 hops (`X-Tandem-Hops` headers)                                                                                                                                    | `too_many_hops`                        |
| The message carries an `X-Tandem-Message` header (our own outbound mail bouncing back)                                                                                                           | `own_outbound_message`                 |
| `From` is a forwarding address belonging to the same organization                                                                                                                                | `from_forwarding_address`              |
| The message is auto-submitted (`Auto-Submitted`, `Precedence: bulk/list/auto_reply`, `List-Id`, `X-Autoreply`, ...) **and** every recipient is a forwarding address, i.e. no human was addressed | `auto_submitted_to_forwarding_address` |
| The forwarding account was deleted between receipt and processing                                                                                                                                | `account_missing`                      |

Normal auto-replies from customers (addressed to the customer's group address) are kept because
the group address is a human-facing recipient.

Tandem's own outbound mail always carries `X-Tandem-Message: <uuid>`, and test emails carry
`X-Tandem-Forwarding-Test` (which is _not_ a loop marker, so tests arrive).

## Retry and failure semantics

| Stage                            | Behaviour                                                                                                                                                                                             |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Worker cannot reach Tandem / 5xx | 3 attempts with 0.5s, 1s backoff, then `setReject("Tandem temporarily unavailable, please retry")`. The sending MTA queues and retries for hours or days; nothing is lost.                            |
| Worker gets `401`                | Misconfigured secret. The message is rejected with the same retry message so it is redelivered once the secret is fixed.                                                                              |
| Worker gets `413`                | `setReject("Message too large")`: the sender receives a bounce.                                                                                                                                       |
| Webhook duplicate `id`           | `200 {"status":"duplicate"}`; nothing dispatched. Cloudflare may redeliver after a timeout; `WebhookReceipt` guarantees exactly-once processing.                                                      |
| Unknown or disconnected address  | `202 {"status":"unknown_recipient"}`: never 4xx/5xx, so Cloudflare does not retry mail for addresses we deleted. Logged at info level.                                                                |
| Accepted                         | `202 {"status":"queued"}`; the `.eml` is in `storage/app/inbound/` and `IngestForwardedEmail` is queued on `mail-inbound`.                                                                            |
| Job failure                      | Receipt status `failed` with `payload.error`; the job retries 5 times (30s, 2m, 10m, 30m). After the last attempt the `.eml` stays on disk for manual recovery and the account's `last_error` is set. |
| Job success                      | Account `last_synced_at`/`status=connected`, `sync_state.received_count` incremented, receipt `processed`, temp file deleted.                                                                         |

Idempotency inside the job: the ingestor dedupes on `provider_message_id = fwd:sha1(Message-ID)`
(falling back to the raw bytes) and on the `Message-ID` header, so a retried job never creates a
second message.

## Size limits

- Worker: `MAX_BYTES` (default 25 MiB) checked against `message.rawSize` before reading and while
  streaming.
- Tandem: `TANDEM_FORWARDING_MAX_BYTES` (default 25 MiB) checked against the declared `size` and
  the decoded length; both return `413`.
- Cloudflare Email Routing itself caps messages at 25 MiB. Keep both limits at or below that.
- Base64 inflates the body by about 33%; make sure the web server/proxy (`client_max_body_size`,
  `post_max_size`) allows at least `MAX_BYTES * 1.37`.

## Security notes

- Every webhook request is authenticated with `X-Tandem-Signature: t=<unix>,v1=<hex>` where
  `v1 = HMAC-SHA256(secret, "<t>.<raw body>")`. Requests older or newer than 300 seconds are
  rejected, which limits replay; `hash_equals` prevents timing attacks. Missing secret in Tandem
  returns `401` for everything and logs an error.
- The webhook route has no session or CSRF middleware and is rate limited by `throttle:webhooks`
  (600/min per IP).
- Only the metadata (envelope, size, a few headers) is stored in `webhook_receipts.payload`; the
  raw MIME lives on the `local` disk until ingested and is deleted afterwards.
- Forwarding addresses are random 10-character strings from a 32-character alphabet (about 50
  bits) and are not guessable; `inbound_token` (48 random characters) is reserved for future
  per-address authentication.
- Attachments pass through the same `AttachmentStore`/`HtmlSanitizer` pipeline as every other
  provider; blocked extensions and HTML sanitisation apply unchanged.
- Rotate the secret by setting the new value in Tandem's `.env` first (the Worker gets `401` and
  rejects with a retry message for a few seconds), then `wrangler secret put`.

## Local development

```sh
# create a forwarding address in the UI (or via tinker), then:
php artisan mail:forwarding:simulate x7k2m9qp4a                       # ingest simple.eml directly
php artisan mail:forwarding:simulate x7k2m9qp4a path/to/other.eml     # any .eml
php artisan mail:forwarding:simulate x7k2m9qp4a --http                # through the signed webhook
php artisan mail:forwarding:simulate x7k2m9qp4a --hops=5              # exercise loop prevention
```

With `MAIL_MAILER=log` the "Send test email" button writes the message to `storage/logs/laravel.log`.
