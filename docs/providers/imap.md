# IMAP / SMTP provider

The IMAP adapter connects any standards-compliant mailbox to Tandem: IMAP for reading, flags and
folder moves, SMTP for sending. It lives in `app/Services/Mail/Providers/Imap/` and implements the
shared `MailProvider` contract, so conversations, threading, attachments and sanitising are handled
by the same ingestion pipeline as every other provider.

## Settings

Stored on the `mail_accounts` row.

| Column        | Key                  | Values                                                              |
| ------------- | -------------------- | ------------------------------------------------------------------- |
| `settings`    | `imap.host`          | Hostname                                                            |
|               | `imap.port`          | `993` (SSL/TLS) or `143` (STARTTLS / none)                          |
|               | `imap.encryption`    | `ssl`, `tls` (both = implicit TLS), `starttls`, `none`              |
|               | `imap.username`      | Login name, usually the address                                     |
|               | `imap.validate_cert` | `true` (default). Set `false` only for self-signed internal servers |
|               | `smtp.host`          | Hostname                                                            |
|               | `smtp.port`          | `587` (STARTTLS) or `465` (implicit TLS)                            |
|               | `smtp.encryption`    | `ssl` (implicit), `tls`/`starttls` (STARTTLS), `none`               |
|               | `smtp.username`      | Defaults to the IMAP username                                       |
|               | `smtp.append_sent`   | `true` (default): copy sent mail into the Sent folder with `\Seen`  |
| `credentials` | `password`           | IMAP password / app password                                        |
|               | `smtp_password`      | Optional, when SMTP uses a different password                       |

Application config (`config/tandem.php`, `imap` section):

| Key                 | Env                            | Default | Meaning                                               |
| ------------------- | ------------------------------ | ------- | ----------------------------------------------------- |
| `poll_minutes`      | `TANDEM_IMAP_POLL_MINUTES`      | `2`     | How often `mail:imap:poll` queues an incremental sync |
| `initial_sync_days` | `TANDEM_IMAP_INITIAL_SYNC_DAYS` | `90`    | Window of history fetched on first sync               |
| `batch_size`        | `TANDEM_IMAP_BATCH_SIZE`        | `100`   | Messages fetched per folder per job run               |

## Security

- `credentials` is an `encrypted:array` cast: passwords are encrypted at rest with `APP_KEY`
  (AES-256-CBC) and never leave the server. Rotating `APP_KEY` without re-encrypting invalidates
  stored passwords; users then see the account as _Needs reconnect_.
- Passwords are never written to logs, sync run stats or error messages; the adapter translates
  library errors into short human-readable messages before they are stored.
- Certificates are validated by default. Disabling validation is a per-account opt-in.
- Prefer provider "app passwords" over the primary account password where the provider offers them
  (Fastmail, iCloud, Zoho, Yahoo all require this when two-factor is enabled).

## How sync works

### Identity

Every message is addressed as `{folder}:{uidvalidity}:{uid}` (`provider_message_id`) and also by
its `Message-ID` header hash. Ingestion is idempotent on both, so a message that reappears under a
new UID (moved on the server, or after a UIDVALIDITY reset) is matched and updated, never
duplicated.

### Folder discovery

`ImapProvider::syncFolders()` upserts `mail_folders` from `LIST`. Types come from RFC 6154
special-use attributes first (`\Sent`, `\Drafts`, `\Trash`, `\Junk`, `\Archive`, `\Flagged`,
`\All`) and conventional names second (`Sent Items`, `Deleted Messages`, `Junk E-mail`, `Spam`,
`Archives`, `All Mail`, ...). `\Noselect` folders are stored but not selectable. Virtual
"All Mail" folders (Gmail over IMAP) are disabled for sync because they would duplicate every
message. Folders that vanish from the server are disabled, not deleted, because messages reference
them.

### Initial sync

For each folder with `sync_enabled` and `is_selectable`:

1. `SELECT` the folder, persist `uid_validity` and `uid_next`.
2. `UID SEARCH SINCE <now - initial_sync_days>`, keep UIDs above `last_synced_uid`, ascending.
3. Fetch one batch of `batch_size` messages with `BODY.PEEK[]` (never marks mail as read), parse
   the raw MIME, map `\Seen` / `\Flagged` / `\Draft` plus the folder type into a `MailboxState`
   and ingest.
4. Persist `last_synced_uid` after the batch. If the folder still has pending UIDs the result is
   flagged `hasMore` and the sync job re-dispatches itself; otherwise the cursor is fast-forwarded
   to `UIDNEXT - 1` so mail older than the window is never fetched later.

`initial_sync_completed_at` is set once every folder is drained. The process is resumable at any
point: a killed job simply continues from the stored cursors.

### Incremental sync

Per folder, one connection per run:

1. `SELECT`; if `UIDVALIDITY` differs from the stored value the server renumbered its UIDs. The
   cursor is reset to `0`, local `imap_uid`s for that folder are cleared, and the folder is
   re-synced inside the normal window. Messages are matched by `Message-ID`, so nothing is lost or
   duplicated.
2. `UID SEARCH UID {last_synced_uid + 1}:*` and ingest, batched exactly like the initial sync.
3. **Flag refresh window**: for local messages in this folder received in the last 7 days
   (`ImapProvider::FLAG_REFRESH_DAYS`) fetch `FLAGS` in one command. Read / starred changes are
   applied, and the conversation's flags and counters are recomputed.
4. **Deletions and moves**: a locally known UID that the server no longer returns was deleted or
   moved. The local message is soft-deleted; if it was moved, the destination folder's sync
   re-attaches it (by `Message-ID`) and restores it.

### Write-back

| Action          | IMAP                                                                                                |
| --------------- | --------------------------------------------------------------------------------------------------- |
| mark (un)read   | `UID STORE ±FLAGS (\Seen)`                                                                          |
| star            | `UID STORE ±FLAGS (\Flagged)`                                                                       |
| archive         | `UID MOVE` to the Archive folder (created as `Archive` / `INBOX.Archive` when the mailbox has none) |
| trash / restore | `UID MOVE` to Trash / back to INBOX                                                                 |
| spam            | `UID MOVE` to Junk / back to INBOX                                                                  |
| move to folder  | `UID MOVE` to the chosen folder                                                                     |
| labels          | not supported (`UnsupportedOperationException`)                                                     |

After a move the local row is updated with the new folder, UID and provider id using the server's
`COPYUID` response (UIDPLUS). Servers without UIDPLUS are handled by looking the message up by
`Message-ID` in the destination; servers without `MOVE` fall back to `COPY` + `\Deleted` +
`EXPUNGE`.

### Sending

`send()` builds the message with `SymfonyEmailBuilder`, generates a `Message-ID` of the form
`{uuid}@{sender-domain}` when the caller did not provide one, delivers through Symfony's
`EsmtpTransport`, and (unless `smtp.append_sent` is `false`) appends the exact bytes that were sent
to the Sent folder with `\Seen`. The returned `providerMessageId` points at that copy so the next
Sent-folder sync recognises it instead of ingesting a duplicate.

## IDLE vs polling

- **Polling** (`mail:imap:poll`) is scheduled every minute in `routes/console.php` and queues an
  incremental `SyncMailAccount` job for every IMAP account whose `last_synced_at` is older than
  `poll_minutes`. It is the safety net and the only mechanism for servers without IDLE.
- **IDLE** (`mail:imap:idle`) keeps a connection open on INBOX and queues a sync the moment the
  server announces `EXISTS` / `EXPUNGE`. Delivery becomes near-instant and the poll becomes a
  no-op for that account. The command re-issues IDLE every `--timeout` seconds (default 1740 s,
  RFC 2177 recommends staying under 29 minutes), reconnects with exponential backoff
  (5 s → 5 min) and stops cleanly on `SIGTERM` / `SIGINT`.

```
php artisan mail:imap:idle                       # round-robin over all connected IMAP accounts
php artisan mail:imap:idle {account-uuid}        # one long-lived connection for one account
php artisan mail:imap:idle {account-uuid} --once # single cycle, handy for health checks
```

Round-robin mode divides the timeout between accounts and is only meant for a handful of
mailboxes; for real deployments run one process per account.

### Running the IDLE listener under Horizon

Horizon manages queue workers, not arbitrary processes, so the listener runs next to it under the
same Supervisor (or systemd) that keeps `horizon` alive. Example Supervisor program:

```ini
[program:tandem-imap-idle]
command=php /var/www/tandem/artisan mail:imap:idle
directory=/var/www/tandem
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=60
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/tandem/storage/logs/imap-idle.log
```

Make sure a Horizon supervisor consumes the `mail-sync` queue, e.g. in `config/horizon.php`:

```php
'supervisor-mail' => [
    'connection' => 'redis',
    'queue' => ['mail-sync', 'mail-outbound'],
    'balance' => 'auto',
    'maxProcesses' => 4,
    'timeout' => 300,
],
```

Sync jobs are unique per account while queued and serialised with `WithoutOverlapping`, so IDLE
and polling can both fire without doing duplicate work.

## Common provider settings

| Provider                 | IMAP host               | IMAP port / enc. | SMTP host               | SMTP port / enc.            | Notes                                                                                         |
| ------------------------ | ----------------------- | ---------------- | ----------------------- | --------------------------- | --------------------------------------------------------------------------------------------- |
| Fastmail                 | `imap.fastmail.com`     | 993 / ssl        | `smtp.fastmail.com`     | 465 / ssl                   | Requires an app password (Settings → Privacy & Security → Integrations)                       |
| Outlook.com / Office 365 | `outlook.office365.com` | 993 / ssl        | `smtp.office365.com`    | 587 / starttls              | Basic auth is disabled for many tenants; needs an app password or an admin enabling SMTP AUTH |
| iCloud Mail              | `imap.mail.me.com`      | 993 / ssl        | `smtp.mail.me.com`      | 587 / starttls              | App-specific password required; username is the full iCloud address                           |
| Zoho Mail                | `imap.zoho.com` (`.eu`) | 993 / ssl        | `smtp.zoho.com` (`.eu`) | 465 / ssl                   | Enable IMAP in Zoho settings; app password when 2FA is on                                     |
| Yahoo                    | `imap.mail.yahoo.com`   | 993 / ssl        | `smtp.mail.yahoo.com`   | 465 / ssl                   | App password required                                                                         |
| Gmail over IMAP          | `imap.gmail.com`        | 993 / ssl        | `smtp.gmail.com`        | 465 / ssl                   | Prefer the native Gmail provider; `[Gmail]/All Mail` is skipped automatically                 |
| Generic cPanel / Dovecot | `mail.yourdomain.tld`   | 993 / ssl        | `mail.yourdomain.tld`   | 465 / ssl or 587 / starttls | Folder names are often `INBOX.Sent`, `INBOX.Trash`; handled by the name mapper                |

## Troubleshooting

| Symptom                                                | Cause / fix                                                                                                                                                  |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| "The IMAP server rejected the username or password."   | Wrong credentials, or the provider requires an app password / has IMAP disabled. Account status becomes _Needs reconnect_; fix via the update endpoint.      |
| "Could not establish a secure connection ..."          | Port/encryption mismatch (993 needs `ssl`, 143 needs `starttls` or `none`) or an untrusted certificate. Fix the setting rather than disabling validation.    |
| "Could not reach the IMAP server at host:port ..."     | DNS, firewall or the server is down. The sync job retries with backoff; nothing is lost.                                                                     |
| New mail arrives late                                  | IDLE is not running (check `mail:imap:idle` process and `IDLE` in the connection test capabilities) and polling is limited by `poll_minutes`.                |
| Messages reappear as unread / duplicated conversations | Should not happen: check `sync_runs.stats.errors` for parse failures; those UIDs are skipped and logged with `IMAP message could not be ingested`.           |
| Whole folder re-synced                                 | `UIDVALIDITY` changed on the server (mailbox rebuilt or migrated). Expected; existing messages are matched by `Message-ID`.                                  |
| Sent mail not in the Sent folder                       | `smtp.append_sent` is `false`, or the server has no Sent folder and refused `CREATE`; see `Could not append sent message` warnings in the log.               |
| Old mail missing                                       | Only `initial_sync_days` of history is fetched by design. Raise the value and re-run an initial sync (clear `initial_sync_completed_at` and folder cursors). |
| Archive lands in `INBOX.Archive`                       | The server uses an INBOX namespace; the adapter detected sibling folders prefixed with `INBOX.` and followed suit.                                           |
