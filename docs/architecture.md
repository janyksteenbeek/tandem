# Tandem architecture

## Tenancy

```
Organization ─┬─ Membership (owner | admin | member) ─ User
              ├─ Team ─ TeamMember (lead | member)
              ├─ Inbox (type = shared) ─┬─ inbox_teams (admin | agent | viewer)
              │                         ├─ inbox_users (admin | agent | viewer)
              │                         └─ MailAccount (gmail | imap | forwarding) ─ SenderIdentity, MailFolder, SyncRun
              ├─ Tag, SavedView, MessageTemplate, AutomationRule ─ AutomationRun
              └─ settings: Google OAuth client, Pub/Sub topic, business hours, outbound SMTP

User ─ Inbox (type = personal, organization_id = null) ─ MailAccount ─ Conversation ─ Message ─ Attachment
```

**Access** is resolved by `Inbox::roleFor(User)`: the owner of a personal inbox, a direct grant, a team grant, or organization owner/admin. Organization administrators get nothing on personal inboxes — personal mail is private unless the user shares it. Policies (`InboxPolicy`, `ConversationPolicy`, `DraftPolicy`, `CommentPolicy`, `MailAccountPolicy`) sit on top of that single function, and every list query is scoped through `Conversation::visibleInInbox` + `Inbox::accessibleBy`.

## Mail pipeline

```
Gmail push / IMAP IDLE / Cloudflare Worker / scheduler
        │
        ▼
 SyncMailAccount / IngestForwardedEmail (Horizon, idempotent, unique per account)
        │   provider adapter → raw MIME (+ provider ids, labels, flags)
        ▼
 MimeMessageParser  ── canonical IncomingMessage (addresses, bodies, headers, attachments, MailboxState)
        ▼
 MessageIngestor    ── dedupe (provider id, Message-ID hash) → ThreadResolver (threadId → References → subject+participants)
        │              sanitize HTML (HTMLPurifier) → store message + attachments + raw .eml → contacts → counters → flags
        ▼
 MessageIngested event ──► RunAutomations (queue: automations)
                       ──► BroadcastIngestedMessage (Reverb: inbox.{id}, conversation.{uuid}, user.{id}) + notifications
```

Every adapter implements `App\Services\Mail\Contracts\MailProvider` (connection test, folder discovery, initial/incremental sync, attachment fetch, send, read/star/archive/trash/spam/move/label write-back, disconnect). Capabilities are declared per provider (`MailProvider::capabilities()`), and `PushMailboxChange` only mirrors local changes to providers that support them.

Outbound mail is a `Message` row with `delivery_status`; `SendOutgoingMessage` is unique per message, re-checks the stored state before every attempt, and falls back to the application mailer / organization SMTP for forwarding-only inboxes. Failures are surfaced on the message and in the activity timeline — nothing is lost silently.

## Conversations

A `Conversation` belongs to exactly one inbox. Flags (`in_inbox`, `is_archived`, `is_trashed`, `is_spam`, `is_starred`) are aggregated from per-message `mailbox_state`, which keeps Gmail thread semantics and IMAP folder semantics consistent. Team state (`status`, `assignee`, `priority`, tags, followers) lives on the conversation; personal inboxes just do not show those controls.

**Sharing vs transferring.** `ConversationSharing::share()` inserts a `conversation_shares` row: the conversation remains owned by the origin inbox and additionally appears in the target. `transfer()` changes `inbox_id` (recording `origin_inbox_id`) and, when a personal conversation moves to a team inbox, leaves a share back to the owner so they never lose their own thread. Both are internal only; the provider mailbox is never modified, which the UI states explicitly.

Every state change goes through `ConversationActions`, which writes a `ConversationEvent`, fires the domain event (automations), broadcasts `ConversationUpdated`, and queues provider write-back where applicable.

## Realtime

Reverb channels: `private-inbox.{id}` (list updates), `private-conversation.{uuid}` (messages, comments, activity, delivery state), `presence.conversation.{uuid}` (who is viewing; typing and reply-collision via client whispers), `private-user.{id}` (counter nudges and notifications). The Vue app applies updates optimistically and reconciles with server responses.

## Security

- OAuth tokens and IMAP passwords are stored in `mail_accounts.credentials` (encrypted cast); organization Google secrets and SMTP settings are encrypted casts too.
- OAuth state is a signed nonce kept in the session with its intent; the Pub/Sub endpoint requires a shared token; the Cloudflare Worker signs every delivery (HMAC-SHA256 with timestamp tolerance); all webhooks are deduplicated through `webhook_receipts`.
- Email HTML is purified on ingest and rendered in a sandboxed, script-less iframe; attachments stream through an authorized controller with `nosniff` and a restrictive CSP; dangerous upload extensions are refused.
- Audit trail: `conversation_events`, `automation_runs`, `sync_runs`, `webhook_receipts`, `audit_logs`.
