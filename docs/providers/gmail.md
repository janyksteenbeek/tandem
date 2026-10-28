# Gmail provider

Tandem connects Gmail and Google Workspace mailboxes through the Gmail REST API using OAuth 2.0.
Mail is pulled as raw RFC 5322 (`messages.get?format=raw`) so it flows through the same MIME
parser and ingestor as every other provider; changes are pushed to Tandem in near real time via
Google Cloud Pub/Sub (`users.watch`) and reconciled through the Gmail history API.

This guide walks through the one-time Google Cloud setup, the Tandem configuration, and how the
adapter behaves in production.

## 1. Create a Google Cloud project and enable the Gmail API

1. Open <https://console.cloud.google.com/> and create a project (for example `tandem-prod`).
2. Go to **APIs & Services → Library**, search for **Gmail API** and click **Enable**.
3. Note the project id; you will need it for the Pub/Sub topic name.

## 2. Configure the OAuth consent screen

1. **APIs & Services → OAuth consent screen**.
2. Choose **External** (or **Internal** if every user is in your Google Workspace organization).
3. Fill in the app name, support email and developer contact.
4. Under **Scopes**, add exactly the scopes Tandem requests (`config/tandem.php`, `gmail.scopes`):

   | Scope | Why |
   | --- | --- |
   | `https://www.googleapis.com/auth/gmail.modify` | read mail, send mail, change labels (read/unread, star, archive, trash, spam) |
   | `https://www.googleapis.com/auth/userinfo.email` | learn which Google account was connected |
   | `https://www.googleapis.com/auth/userinfo.profile` | default display name for the sender identity |

   `gmail.modify` is a restricted scope. While the app is in **Testing** mode only listed test
   users can connect and refresh tokens expire after seven days; publish the app (and complete
   Google's verification for restricted scopes) before using it with customers.
5. Add the Google accounts that will connect during testing under **Test users**.

## 3. Create an OAuth client (Web application)

1. **APIs & Services → Credentials → Create credentials → OAuth client ID**.
2. Application type: **Web application**.
3. **Authorized redirect URIs**: add

   ```
   {APP_URL}/mail/connect/gmail/callback
   ```

   where `{APP_URL}` is the `APP_URL` of your Tandem installation (for example
   `https://mail.example.com/mail/connect/gmail/callback`). Local development with HTTP works
   for `http://localhost` style URLs only.
4. Copy the **Client ID** and **Client secret**.

### Where to put the Client ID and Secret

Tandem supports two levels of OAuth credentials:

- **Per organization (recommended for multi-tenant installs)**: in Tandem go to
  **Organization settings → Integrations → Google** and paste the Client ID, Client secret and
  (optionally) the Pub/Sub topic. Every mailbox connected from that organization, including the
  personal mailboxes of its members, uses this client. The secret is stored encrypted
  (`organizations.google_oauth_client_secret`).
- **Global fallback**: set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`. These are used
  when the organization has not configured its own client.

The account remembers which organization's client issued its tokens
(`credentials.oauth_organization_id`) so token refreshes keep working even if the user later
switches organizations.

## 4. Create the Pub/Sub topic for push notifications

Gmail publishes a small notification to a Pub/Sub topic whenever a watched mailbox changes.
Tandem then runs an incremental sync for that account.

1. **Pub/Sub → Topics → Create topic**, for example `gmail-push`. The full topic name is
   `projects/{PROJECT_ID}/topics/gmail-push`.
2. Grant Gmail permission to publish to it: open the topic, **Permissions → Add principal**, add
   `gmail-api-push@system.gserviceaccount.com` with the role **Pub/Sub Publisher**.
3. Create a **push subscription** on the topic:
   - Delivery type: **Push**
   - Endpoint URL:

     ```
     {APP_URL}/webhooks/gmail/push/{TANDEM_GMAIL_PUBSUB_TOKEN}
     ```

     `TANDEM_GMAIL_PUBSUB_TOKEN` is a long random secret from your `.env`
     (`openssl rand -hex 24`). Requests with a different token are answered with 404.
   - Acknowledgement deadline: 60 seconds is plenty; the endpoint answers immediately and queues
     the work.
   - Retry policy: exponential backoff. Redeliveries are harmless; every Pub/Sub `messageId` is
     processed once (`webhook_receipts`).
4. Put the topic name in Tandem: either per organization (**Organization settings → Integrations
   → Google → Pub/Sub topic**) or globally via `GOOGLE_PUBSUB_TOPIC`.

The push endpoint lives in `routes/webhooks.php`: it runs without session or CSRF middleware and
is rate limited by the `webhooks` limiter.

## 5. Environment variables

```dotenv
# Global OAuth client (fallback when an organization has no own client)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
# Default Pub/Sub topic, e.g. projects/tandem-prod/topics/gmail-push
GOOGLE_PUBSUB_TOPIC=

# Secret appended to the push endpoint URL
TANDEM_GMAIL_PUBSUB_TOKEN=

# Optional tuning (defaults shown)
TANDEM_GMAIL_INITIAL_SYNC_DAYS=90      # how far back the first import goes
TANDEM_GMAIL_RECONCILE_MINUTES=15      # accounts idle longer than this get a safety sync
TANDEM_GMAIL_SYNC_BATCH_SIZE=100       # full message fetches per job run
```

Workers must process the `mail-sync` queue (Horizon supervisor or `queue:work --queue=mail-sync`)
and the scheduler (`schedule:run`) must be running.

## 6. Connecting a mailbox

- **Personal mailbox**: `GET /mail/connect/gmail` (route `mail.gmail.redirect`). After consent
  Tandem creates a personal inbox named after the Google address, the `MailAccount`, a default
  sender identity, registers the push watch and queues the initial import.
- **Shared inbox**: `GET /mail/connect/gmail?inbox={inbox uuid}`. Only inbox admins may do this;
  the account is attached to that inbox and owned by its organization.

Reconnecting an address that is already linked updates the stored tokens in place (the old
refresh token is kept when Google does not issue a new one), clears the error state and resumes
syncing.

## 7. How syncing works

| Phase | What happens |
| --- | --- |
| Initial import (`SyncRunType::Initial`) | Records the mailbox `historyId`, mirrors Gmail labels into `mail_folders`, then pages through `messages.list` with `newer_than:{days}d -in:chats` (spam and trash included). Each page token is stored in `sync_state.initial_page_token`, so a crashed job resumes where it stopped. At most `TANDEM_GMAIL_SYNC_BATCH_SIZE` messages are fetched per job run; the job re-dispatches itself until the list is exhausted and then sets `initial_sync_completed_at`. Drafts are skipped. |
| Push notification | Pub/Sub delivers `{emailAddress, historyId}`. If the `historyId` is newer than `sync_state.history_id`, an incremental `SyncMailAccount` job is queued (bursts collapse into one job). |
| Incremental sync | `history.list` from the stored cursor. `messageAdded` → fetch raw and ingest; `labelAdded`/`labelRemoved` → re-read the label set and refresh the message and conversation flags; `messageDeleted` → soft delete locally. The cursor advances only after every page has been applied. |
| Reconcile | Gmail keeps history for a limited time. A `404` on `history.list` (or a missing cursor) starts a reconcile: the current `historyId` is remembered, mail since `last_synced_at` (minus one day, or the last 7 days) is listed and re-ingested in batches, and the cursor is moved to the remembered id when done. Ingestion is idempotent on the Gmail message id, so nothing is duplicated. |
| Watch renewal | Google expires a watch after 7 days. `mail:gmail:renew-watches` runs hourly and renews every healthy account whose watch is missing or expires within `watch_renew_before_hours` (24). |
| Safety net | `mail:gmail:reconcile` runs every 15 minutes and queues an incremental sync for healthy accounts that have not synced for `TANDEM_GMAIL_RECONCILE_MINUTES`, covering lost push messages. |

Mailbox actions from Tandem (read/unread, star, archive, move to inbox, trash/restore, spam,
labels) are applied with `messages.batchModify`, `messages.trash` and `messages.untrash`.
Sending uses `messages.send` with the raw MIME built by `SymfonyEmailBuilder` and passes the
Gmail `threadId` so replies stay in the original thread.

## 8. Troubleshooting

**Account status `needs_reauth`**
Google rejected the refresh token (`invalid_grant`) or an API call returned 401/403 for missing
scopes. Common causes: the user revoked access at <https://myaccount.google.com/permissions>,
the password was changed on a Workspace account with that policy, the OAuth app is still in
*Testing* mode (tokens expire after 7 days), or the consent screen scopes were changed. The
user must reconnect via `/mail/connect/gmail`; the account keeps its inbox and history.

**`history.list` returns 404 / "historyId too old"**
Expected after long outages. Tandem automatically switches to the reconcile path described above
and logs `Gmail reconcile started`. If reconciles happen constantly, make sure the scheduler and
the `mail-sync` queue are running so the cursor keeps advancing.

**No push notifications arrive**
- Check the subscription's endpoint URL contains the current `TANDEM_GMAIL_PUBSUB_TOKEN`.
- Confirm `gmail-api-push@system.gserviceaccount.com` has **Pub/Sub Publisher** on the topic.
- The watch may have expired: run `php artisan mail:gmail:renew-watches` and inspect
  `mail_accounts.watch_expires_at`.
- Pub/Sub only delivers to HTTPS endpoints with a valid certificate (localhost needs a tunnel).
- Even without push, the 15-minute reconcile keeps accounts in sync.

**Rate limits (429 / `userRateLimitExceeded`)**
The adapter raises `RateLimitedException`; the job is released and retried after the
`Retry-After` value (default 60 s). Reduce `TANDEM_GMAIL_SYNC_BATCH_SIZE` if many accounts are
importing simultaneously.

**Sending fails with `Request Entity Too Large`**
`messages.send` with a JSON `raw` body is limited by Google to roughly 35 MB of encoded MIME.
Lower `TANDEM_MAX_UPLOAD_BYTES` or deliver large attachments via links.
