# Automations & auto-responders

Automations let an organization react to inbox activity without a human in the loop: route
billing mail to the finance team, tag VIP customers, send an out-of-office reply after hours, or
notify a manager when a conversation is reopened. Everything lives in the backend under
`app/Services/Automations/`; the rule builder UI only has to produce the JSON shapes below.

## Concepts

| Concept   | Model                    | Notes                                                                                                                                                                                                                                            |
| --------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Rule      | `AutomationRule`         | Belongs to an organization. `inbox_id = null` means "every shared inbox of the organization"; otherwise the rule only fires for conversations **owned** by that inbox (conversations merely shared into it do not count). Ordered by `position`. |
| Trigger   | `AutomationTrigger` enum | When the rule is evaluated (see below).                                                                                                                                                                                                          |
| Condition | JSON on the rule         | Combined with `match: "all"` or `match: "any"`. A rule with no conditions always matches.                                                                                                                                                        |
| Action    | JSON on the rule         | Executed in order inside one database transaction per rule.                                                                                                                                                                                      |
| Run       | `AutomationRun`          | Audit row per rule per evaluation: `matched`, `skipped` or `failed` with full details.                                                                                                                                                           |
| Template  | `MessageTemplate`        | Subject + HTML body with variables, used by `reply_with_template`.                                                                                                                                                                               |
| Ledger    | `AutoresponderSend`      | One row per automated reply (rule, conversation, recipient, sent_at); drives cooldowns.                                                                                                                                                          |

Personal inboxes (`organization_id = null`) never run automations.

### Triggers

| Value                                             | Fires when                                                                                                                                             |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `conversation_received`                           | An inbound message creates a new conversation (`MessageIngested` with `isNewConversation`).                                                            |
| `reply_received`                                  | An inbound message lands in an existing conversation.                                                                                                  |
| `conversation_assigned`                           | A conversation gets an assignee (`ConversationAssigned` with a non-null assignee).                                                                     |
| `conversation_moved`                              | A conversation is moved to another inbox.                                                                                                              |
| `conversation_closed`                             | Status changes to `closed`.                                                                                                                            |
| `conversation_reopened`                           | Status changes from `closed` to `open`, or an inbound message reopens a closed conversation.                                                           |
| `tag_added`                                       | A tag is attached.                                                                                                                                     |
| `business_hours_started` / `business_hours_ended` | Evaluated every minute by `automations:business-hours` against every **open** conversation in the relevant inboxes (max 500 per organization per run). |

Event-driven triggers are handled by the queued listener `App\Listeners\Automations\RunAutomations`
on the `automations` queue. Make sure a Horizon supervisor processes that queue.

## JSON shapes

### Condition

```json
{ "field": "sender", "operator": "equals", "value": "@acme.com" }
```

| Field            | Compares                                                                                                              | Value type     | Operators                                                                                                       |
| ---------------- | --------------------------------------------------------------------------------------------------------------------- | -------------- | --------------------------------------------------------------------------------------------------------------- |
| `inbox`          | Owning inbox uuid                                                                                                     | `inbox` (uuid) | equals, not_equals, in, not_in                                                                                  |
| `sender`         | From address of the triggering inbound message (falls back to the latest inbound message, then the first participant) | `email`        | equals, not_equals, contains, not_contains, starts_with, ends_with, matches_regex, in, not_in, is_set, is_empty |
| `recipient`      | All To/Cc/Bcc addresses of that message (any may match)                                                               | `email`        | same as sender                                                                                                  |
| `subject`        | Conversation subject                                                                                                  | `text`         | equals, not_equals, contains, not_contains, starts_with, ends_with, matches_regex, is_set, is_empty             |
| `body`           | Plain-text body of the inbound message (falls back to the snippet)                                                    | `text`         | same as subject                                                                                                 |
| `tag`            | Slugs of attached tags (any may match; value is slugified, so `VIP` matches `vip`)                                    | `tag` (slug)   | equals, not_equals, contains, not_contains, in, not_in, is_set, is_empty                                        |
| `assignee`       | Assignee user id                                                                                                      | `user` (id)    | equals, not_equals, in, not_in, is_set, is_empty                                                                |
| `business_hours` | Whether the organization is open right now                                                                            | `boolean`      | is_true, is_false                                                                                               |
| `status`         | `open`, `pending`, `snoozed`, `closed`                                                                                | `status`       | equals, not_equals, in, not_in                                                                                  |
| `priority`       | `none`, `low`, `normal`, `high`, `urgent`                                                                             | `priority`     | equals, not_equals, in, not_in                                                                                  |

Operator value shapes: `in` / `not_in` take an array of strings, `is_set` / `is_empty` / `is_true` /
`is_false` take no value (`null`), everything else takes a single string. All text comparisons are
case-insensitive. Email values support domains: `@acme.com` (or `acme.com`) matches any address at
that domain. `matches_regex` accepts a bare pattern (wrapped as `~pattern~iu`) or a fully delimited
one like `/^inv-\d+/i`.

The catalog served to the UI (`AutomationCatalog::toArray()`) lists `fields` (with `value_type` and
the allowed `operators`), `operators` (with `value_shape`), `actions` (with `params` schema),
`triggers`, `match`, `statuses`, `priorities`, `strategies`, `variables` and
`default_cooldown_minutes`. Use it rather than hard-coding the lists.

### Action

```json
{ "type": "assign_team", "params": { "team_id": 4, "strategy": "round_robin" } }
```

| Type                  | Params                                                        | Behaviour                                                                                                                                                     |
| --------------------- | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `reply_with_template` | `template_id` (required), `cooldown_minutes?`                 | Sends the template as a reply to the inbound sender, see _Auto-responders_.                                                                                   |
| `assign_user`         | `user_id`                                                     | Assigns an organization member.                                                                                                                               |
| `assign_team`         | `team_id`, `strategy: "round_robin" \| "least_busy"`          | Round robin cycles through team members (cache counter per team); least busy picks the member with the fewest open/pending conversations in the organization. |
| `move_to_inbox`       | `inbox_uuid`                                                  | Moves the conversation; `origin_inbox_id` is kept.                                                                                                            |
| `add_tag`             | `tag_id` **or** `tag_name`                                    | `tag_name` finds or creates an organization tag.                                                                                                              |
| `remove_tag`          | `tag_id` **or** `tag_name`                                    | No-op when the tag is missing or not attached.                                                                                                                |
| `set_priority`        | `priority: "none" \| "low" \| "normal" \| "high" \| "urgent"` |                                                                                                                                                               |
| `set_status`          | `status: "open" \| "pending" \| "closed"`                     | Snoozing is not supported.                                                                                                                                    |
| `notify_users`        | `user_ids: []`, `message?`                                    | Database notification (`App\Notifications\AutomationNotification`) for each member.                                                                           |
| `add_follower`        | `user_id`                                                     |                                                                                                                                                               |
| `add_comment`         | `body`                                                        | Internal note authored by the rule creator (or the organization owner). Supports variables.                                                                   |

Each action writes the same `ConversationEvent` a human would (`assigned`, `moved`, `tag_added`,
`priority_changed`, ...) with a `null` actor and `automation_rule_id` in the payload, and dispatches
the matching domain event so realtime listeners stay in sync.

Action results in the audit log have `type`, `status: "done" | "skipped" | "sent"` and a `reason`
when skipped (`already_assigned`, `tag_not_found`, `cooldown`, `auto_submitted`, ...).

### Rule payload (store / update)

```json
{
    "name": "Route billing to Finance",
    "description": "Anything sent to billing@ goes to the finance team",
    "inbox_uuid": null,
    "trigger": "conversation_received",
    "match": "all",
    "is_active": true,
    "cooldown_minutes": null,
    "conditions": [
        {
            "field": "recipient",
            "operator": "equals",
            "value": "billing@acme.com"
        }
    ],
    "actions": [
        { "type": "move_to_inbox", "params": { "inbox_uuid": "9d3f..." } },
        {
            "type": "assign_team",
            "params": { "team_id": 4, "strategy": "least_busy" }
        }
    ]
}
```

`cooldown_minutes` on the rule is a per-conversation cooldown: when set, a rule that already matched
the same conversation inside the window is skipped (`reason: "cooldown"`). The autoresponder uses
`params.cooldown_minutes ?? rule.cooldown_minutes ?? 1440` for its per-recipient ledger.

## Template variables

Templates and `add_comment` bodies use `{{ variable }}` placeholders. Values are HTML-escaped and
unknown variables render as empty strings.

| Variable                                            | Value                                                     |
| --------------------------------------------------- | --------------------------------------------------------- |
| `{{ contact.name }}`                                | Sender name (falls back to the local part of the address) |
| `{{ contact.first_name }}`                          | First word of the name                                    |
| `{{ contact.email }}`                               | Sender address (Reply-To preferred)                       |
| `{{ conversation.subject }}`                        |                                                           |
| `{{ conversation.id }}`                             | Conversation uuid                                         |
| `{{ inbox.name }}`                                  |                                                           |
| `{{ organization.name }}`                           |                                                           |
| `{{ assignee.name }}` / `{{ assignee.first_name }}` | Empty when unassigned                                     |
| `{{ date }}` / `{{ time }}`                         | Now, in the organization's business-hours timezone        |
| `{{ next_business_day }}`                           | e.g. `Monday November 2, 2026`                            |

Template HTML is sanitized with `HtmlSanitizer` on save and again before sending.

## Business hours

Stored on `organizations.business_hours`:

```json
{
    "timezone": "Europe/Amsterdam",
    "days": {
        "mon": [{ "start": "09:00", "end": "17:30" }],
        "tue": [
            { "start": "09:00", "end": "12:00" },
            { "start": "13:00", "end": "17:30" }
        ],
        "wed": [{ "start": "09:00", "end": "17:30" }],
        "thu": [{ "start": "09:00", "end": "17:30" }],
        "fri": [{ "start": "09:00", "end": "16:00" }],
        "sat": [],
        "sun": []
    },
    "holidays": ["2026-12-25", "2026-12-26"]
}
```

`App\Services\Automations\BusinessHours` offers `isOpen()`, `nextOpening()`, `nextBusinessDay()` and
`transitionsBetween()`. An organization without any configured segments is treated as always open,
so `business_hours is_false` never matches for it. The `timezone` key falls back to
`organizations.timezone`, then UTC.

## Auto-responders and loop prevention

`reply_with_template` creates a `Draft` (kind `reply`, sent as the rule creator or, failing that,
the organization owner) and hands it to `MessageSender::sendDraft()`. The stored outbound message
gets `headers = { "x-tandem-autoresponse": "<rule uuid>", "auto-submitted": "auto-replied" }` and
`SendOutgoingMessage` emits them as `Auto-Submitted` / `X-Tandem-Autoresponse`, so other systems
(and Tandem itself) can recognise the reply as automated.

A reply is **never** sent when:

- the inbound message is automated: `Auto-Submitted` other than `no`, `Precedence: bulk|list|junk`,
  `X-Auto-Response-Suppress`, `List-Id`, `X-Tandem-Autoresponse`, `X-Autoreply`, `X-Autorespond`;
- the sender/reply-to is empty or looks like a robot: contains `mailer-daemon`, `noreply`, `no-reply`,
  `no_reply`, `donotreply`, `do-not-reply`, `postmaster` or `bounce`;
- the address belongs to the organization itself (a mail account, sender identity or inbox address);
- the recipient already received a reply from this rule within the cooldown window
  (`params.cooldown_minutes`, else `rule.cooldown_minutes`, else 24 hours);
- there is no inbound message, no sending user, the template is missing, or the rendered body is empty.

Every send is written to `autoresponder_sends`. Skips show up in the run details as
`{"type": "reply_with_template", "status": "skipped", "reason": "..."}`.

### Recursion guard

Actions dispatch real domain events, which would re-enter the engine. `TriggerEchoGuard` registers
one expected "echo" per dispatched event (cache counter keyed by conversation + trigger, 10 minute
TTL); the listener consumes it and skips that occurrence. The same guard tracks in-memory
re-entrancy for synchronous queues. A later human action on the same conversation still triggers
rules normally.

## Audit history

Every evaluation writes an `AutomationRun`:

```json
{
    "status": "matched",
    "trigger": "conversation_received",
    "details": {
        "match": "all",
        "conditions": [
            {
                "field": "sender",
                "operator": "equals",
                "value": "@acme.com",
                "actual": "jane@acme.com",
                "passed": true
            }
        ],
        "actions": [
            { "type": "add_tag", "status": "done", "tag_id": 12, "tag": "VIP" },
            { "type": "set_priority", "status": "done", "priority": "high" }
        ]
    }
}
```

`skipped` runs carry `reason: "conditions_not_met" | "cooldown"` plus the evaluated conditions;
`failed` runs carry `error`, `exception` and the actions performed before the failure (the rule's
transaction is rolled back). A matched run also increments `run_count`, sets `last_run_at` and adds
a `ConversationEvent` of type `automation_ran` with `{ rule_id, rule_uuid, rule_name, trigger, actions }`.

A rule that throws never affects other rules, and the engine never throws into the ingestion path.

## HTTP endpoints

All routes live under `/{organization-slug}/settings/...` and require the owner or admin role.

| Method | Route                                | Name                  | Purpose                                                                                                                    |
| ------ | ------------------------------------ | --------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| GET    | `settings/automations`               | `automations.index`   | Inertia `settings/automations/Index` with `rules`, `catalog`                                                               |
| GET    | `settings/automations/create`        | `automations.create`  | Inertia `settings/automations/Edit` (`rule: null`)                                                                         |
| POST   | `settings/automations`               | `automations.store`   | Create; redirects to edit                                                                                                  |
| GET    | `settings/automations/{rule}/edit`   | `automations.edit`    | Inertia `settings/automations/Edit` with `rule`, `recentRuns`, `inboxes`, `tags`, `users`, `teams`, `templates`, `catalog` |
| PUT    | `settings/automations/{rule}`        | `automations.update`  |                                                                                                                            |
| DELETE | `settings/automations/{rule}`        | `automations.destroy` | Soft delete                                                                                                                |
| PATCH  | `settings/automations/{rule}/toggle` | `automations.toggle`  | Body `{ is_active?: bool }`; omitted flips                                                                                 |
| POST   | `settings/automations/reorder`       | `automations.reorder` | Body `{ rules: [uuid, ...] }` in the new order                                                                             |
| GET    | `settings/automations/{rule}/runs`   | `automations.runs`    | JSON `{ data: [...], meta: {...} }`; `?status=&per_page=&page=`                                                            |
| POST   | `settings/automations/{rule}/test`   | `automations.test`    | Body `{ conversation_uuid, conditions?, match? }` → `{ matched, match, conditions: [...], would_run }`                     |
| GET    | `settings/templates`                 | `templates.index`     | Inertia `settings/templates/Index` with `templates`, `inboxes`, `variables`                                                |
| POST   | `settings/templates`                 | `templates.store`     | `{ name, category?, subject?, body_html, inbox_uuid? }`                                                                    |
| PUT    | `settings/templates/{template}`      | `templates.update`    |                                                                                                                            |
| DELETE | `settings/templates/{template}`      | `templates.destroy`   |                                                                                                                            |
| POST   | `settings/templates/preview`         | `templates.preview`   | `{ body_html, subject?, conversation_uuid? }` → `{ subject, body_html, variables, conversation }`                          |

## Examples

### Out-of-office responder (every inbound message while closed)

```json
{
    "name": "Out of office",
    "trigger": "conversation_received",
    "match": "all",
    "conditions": [
        { "field": "business_hours", "operator": "is_false", "value": null }
    ],
    "actions": [
        {
            "type": "reply_with_template",
            "params": { "template_id": 3, "cooldown_minutes": 1440 }
        }
    ]
}
```

Template body:

```html
<p>Hi {{ contact.first_name }},</p>
<p>
    Thanks for contacting {{ organization.name }}. We're closed right now and
    will get back to you on {{ next_business_day }}.
</p>
```

### After-hours responder for the support inbox only

Same as above, but set `inbox_uuid` to the support inbox and add a second rule on `reply_received`
so follow-ups outside business hours also get a response (the cooldown keeps it to one reply per
sender per day).

### Route billing@ to the Finance team

```json
{
    "name": "Billing to Finance",
    "trigger": "conversation_received",
    "match": "any",
    "conditions": [
        {
            "field": "recipient",
            "operator": "equals",
            "value": "billing@acme.com"
        },
        {
            "field": "subject",
            "operator": "matches_regex",
            "value": "\\b(invoice|receipt|refund)\\b"
        }
    ],
    "actions": [
        {
            "type": "move_to_inbox",
            "params": { "inbox_uuid": "<finance inbox uuid>" }
        },
        {
            "type": "assign_team",
            "params": { "team_id": 7, "strategy": "least_busy" }
        },
        { "type": "add_tag", "params": { "tag_name": "billing" } }
    ]
}
```

### Auto-tag and prioritise VIP domains

```json
{
    "name": "VIP customers",
    "trigger": "conversation_received",
    "match": "any",
    "conditions": [
        {
            "field": "sender",
            "operator": "in",
            "value": ["@bigcorp.com", "@enterprise.io"]
        }
    ],
    "actions": [
        { "type": "add_tag", "params": { "tag_name": "VIP" } },
        { "type": "set_priority", "params": { "priority": "urgent" } },
        {
            "type": "notify_users",
            "params": {
                "user_ids": [2, 5],
                "message": "VIP conversation received"
            }
        },
        {
            "type": "add_comment",
            "params": {
                "body": "Auto-flagged: {{ contact.email }} is a VIP account."
            }
        }
    ]
}
```
