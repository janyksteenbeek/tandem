# Tandem Email Worker

A Cloudflare Email Worker that receives every message sent to the Tandem forwarding domain
(for example `*@forward.tandem.com`) and posts the raw MIME to Tandem's
`POST /webhooks/cloudflare/inbound` endpoint. Tandem then ingests the message into the shared
inbox that owns the address.

The full operator guide lives in [`docs/providers/cloudflare.md`](../../docs/providers/cloudflare.md).

## Requirements

- A domain (or sub-domain) on a Cloudflare zone, used only for Tandem forwarding.
- Node.js 20+ and `npm`.
- A Tandem deployment reachable over HTTPS with `TANDEM_FORWARDING_DOMAIN` and
  `TANDEM_FORWARDING_WEBHOOK_SECRET` set in its `.env`.

## Deploy

1. **Enable Email Routing** on the zone: Cloudflare dashboard -> *Email* -> *Email Routing* ->
   *Get started*. Accept the MX and TXT (SPF) records Cloudflare proposes for the forwarding
   domain. If the forwarding domain is a sub-domain, add the records for that sub-domain.
2. **Install and log in**:

   ```sh
   cd cloudflare/email-worker
   npm install
   npx wrangler login
   ```

3. **Configure** `wrangler.toml`: set `TANDEM_INGEST_URL` to your Tandem URL +
   `/webhooks/cloudflare/inbound`. Adjust `MAX_BYTES` to match `TANDEM_FORWARDING_MAX_BYTES`.
4. **Deploy** the Worker and set the secret (it must equal `TANDEM_FORWARDING_WEBHOOK_SECRET`):

   ```sh
   npm run deploy
   npx wrangler secret put TANDEM_WEBHOOK_SECRET
   ```

5. **Route mail to the Worker**: *Email Routing* -> *Routing rules* -> *Catch-all address* ->
   action *Send to a Worker* -> select `tandem-email-worker` -> enable the rule.
6. **Test**:
   - Locally, without Cloudflare: `php artisan mail:forwarding:simulate <address>` delivers
     `tests/Fixtures/mail/simple.eml` to a forwarding address; add `--http` to go through the
     signed webhook as well.
   - End to end: create a forwarding address in an inbox's settings, send a real email to it (or
     press *Send test email*), then watch `npx wrangler tail` and the inbox.

## Configuration

| Name                  | Type   | Description                                                                 |
| --------------------- | ------ | --------------------------------------------------------------------------- |
| `TANDEM_INGEST_URL`    | var    | Absolute URL of the Tandem webhook.                                          |
| `TANDEM_WEBHOOK_SECRET`| secret | Shared HMAC secret. Same value as `TANDEM_FORWARDING_WEBHOOK_SECRET`.        |
| `MAX_BYTES`           | var    | Reject messages above this size before upload (default 26214400 = 25 MiB).  |
| `FALLBACK_FORWARD_TO` | var    | Optional verified destination address that also receives a copy.            |

## Behaviour

- Reads the raw message, base64-encodes it and POSTs
  `{ id, to, from, size, raw, received_at, hops, headers }` as JSON.
- Signs every request with `X-Tandem-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "t.body")>`.
- Retries up to 3 times with exponential backoff on network errors and 5xx responses.
- `2xx` (`queued`, `duplicate`, `unknown_recipient`) is success: the message is accepted.
- `413` rejects the message as too large.
- Any other final failure rejects with *"Tandem temporarily unavailable, please retry"* so the
  sending MTA keeps the message in its queue and retries instead of silently dropping it.

## Tests

```sh
npm test
```

`test/signature.test.js` pins the HMAC output to the same vector used by
`tests/Feature/Mail/Forwarding/WebhookSignatureTest.php` so the Worker and Tandem provably sign
identically.
