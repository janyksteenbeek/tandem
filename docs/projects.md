# Tandem Projects

Projects is the second product domain in Tandem, next to Mail. It gives an organization products, modules, projects, initiatives and issues, and lets several teams work the same issue with their own workflow. Everything reuses the organization, team, auth, notification, realtime and automation infrastructure that Mail already runs on. Code lives in the `Projects` namespaces listed in [architecture.md](architecture.md).

## Concepts

```
Organization ─┬─ Product (key FRONT) ─┬─ Module (nested) ─ module_team
              │                       ├─ Release
              │                       └─ Workflow (overall issue statuses, optional per product)
              ├─ Project (public | private) ─┬─ Milestone
              │                               ├─ project_team / project_members
              │                               └─ module_project
              ├─ Initiative ─ initiative_project
              ├─ Team ─┬─ Workflow (team statuses)
              │        └─ Cycle
              ├─ IssueLabel (org or product scoped)
              └─ Issue (FRONT-12 | NWL-3) ─┬─ IssueWorkstream (one per team) ─┬─ assignees
                                            │                                   ├─ status (team workflow)
                                            │                                   ├─ cycle, estimate, due date
                                            │                                   └─ WorkstreamDependency
                                            ├─ IssueRelation (blocks | related | duplicate)
                                            ├─ sub-issues (parent_id)
                                            ├─ comments, attachments, activities, followers
                                            ├─ IssueConversationLink ─ IssueSharedContent
                                            └─ labels, modules, milestone, release
```

- **Product**: a thing you ship. Owns a short uppercase **key** (`FRONT`) that prefixes its issue identifiers, optional modules (nested), releases and optionally its own overall-status workflow. Products have members (admin or member) and teams.
- **Module**: a functional area inside a product (Reporting → Exports). Modules can be linked to projects and teams and are a filter and grouping dimension on issues.
- **Project**: time-boxed work with a lead, status, health, dates, milestones, teams and members. `visibility: private` hides the project and all of its issues from anyone who is not a member, lead, product manager or organization admin.
- **Initiative**: a strategic grouping of projects with its own dates and owner.
- **Issue**: the unit of work. Identifiers are `PREFIX-N` where the prefix is the product key, or the organization's issue key (`organizations.issue_key`, derived from the name when empty) for issues without a product. Identifiers are stable: moving an issue to another product keeps its identifier.
- **Workstream**: one team's share of an issue. It has that team's workflow status, its own assignees, estimate, due date, cycle, dependencies and a required flag. An issue has at most one workstream per team.
- **Cycle**: a team's sprint. Cycles contain workstreams, not issues, so the same issue can sit in an Engineering cycle and a QA cycle at once.
- **Release**: a product version that issues attach to.

## Identifiers

`IssueIdentifiers::next()` runs inside the creating transaction. It inserts an `issue_sequences` row for the scope (`org` or `product:{id}`) if missing, locks it with `SELECT … FOR UPDATE`, increments `next_number` and returns the identifier. The row lock serialises concurrent creators, the transaction rolls the counter back if the insert fails, and `unique(organization_id, identifier)` is the backstop. A product key can only be changed while the product has no issues; the organization key may not equal any product key.

## Status model and completion

There are two kinds of workflow, both built from `workflow_statuses` with a `category` (`backlog`, `unstarted`, `started`, `completed`, `canceled`):

- **Issue workflow** for the overall status: the organization default (Backlog, Todo, In Progress, Done, Canceled) or a product's own.
- **Team workflow** for workstreams: created lazily per team (Todo, In Progress, In Review, Done, Canceled) and edited by team leads under the team's Workflow tab.

`IssueCompletion` derives the overall status from the workstreams with one aggregate query and applies **forward-only** transitions:

| Workstreams                                                             | Overall status                                                         |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| Any required workstream started                                         | backlog/unstarted → started (`auto_start`)                             |
| Every required workstream completed or canceled, at least one completed | open → completed (`auto_complete`)                                     |
| Every required workstream canceled                                      | suggested as canceled; the owner is asked, nothing moves automatically |

Nothing ever moves an issue backwards automatically. Reopening a required workstream on a completed issue writes a `completion_conflict` activity and notifies the owner instead. Completing an issue by hand while required work is open raises `CompletionBlockedException` (HTTP 422, `code: workstreams_incomplete`, with the readiness breakdown); a manager may **override**, which records `status_overridden` on the issue and an audit log entry. Automations can never override.

Non-required workstreams (for example a Support help-centre article) never gate completion.

## Dependencies and blockers

`DependencyGraph` holds three kinds of edges: issue → issue (`blocks` relations), workstream → workstream or workstream → issue (`workstream_dependencies`), and the implicit issue → required workstream edge. Adding an edge locks both endpoints and runs a breadth-first cycle check; cycles are rejected with the offending path. `blocked_by_count` on issues and workstreams is always recounted, never incremented. When a node reaches a terminal status its outgoing dependencies are resolved, dependants are recounted, a `dependency_resolved` activity is written and the next team is notified (`BlockerResolved`, `WorkstreamHandoff`).

## Access

`IssueAccess` (a scoped service, flushed per Octane request) resolves one level per user and issue:

| Level       | Who                                                                                            |
| ----------- | ---------------------------------------------------------------------------------------------- |
| Admin       | organization owner or admin                                                                    |
| Manager     | product owner or product admin, project lead, issue owner, lead-team lead                      |
| Participant | lead-team member, workstream team member, assignee, creator, follower                          |
| Viewer      | any organization member, as long as the project is not private or the user is a project member |

Policies in `App\Policies\Projects` sit on top of that. `Issue::visibleTo($user)` mirrors the same rules in SQL for lists, boards, search and reports. Being on a workstream grants access to that issue only, never to the project or product.

## Views, filters and search

`IssueQuery` builds every list, board, calendar, timeline and report query. Filters are nested AND/OR groups validated by `IssueFilterCatalog` (fields, operators and value types are whitelisted, unknown clauses are dropped). Search uses the MySQL FULLTEXT index on `issues.title`/`description_text` with an exact identifier match first and a `LIKE` fallback for very short terms. Sorting is whitelisted with `issues.id` as the tie-break; lists paginate by cursor and merge into the page through Inertia `merge` props.

Saved views (`issue_views`) are personal, team or organization scoped and store filters, display mode, grouping and sorting. Favorites and recent views feed the sidebar.

## Realtime

Broadcast events in `App\Events\Projects\Broadcast` go to `issue.{uuid}`, `project.{uuid}`, `product.{uuid}`, `team.{id}` and `organization.{id}.issues`; issues of private projects only broadcast on the issue and project channels. `presence.issue.{uuid}` shows who is viewing an issue. The frontend keeps one `issueStore` per page, drops stale updates by `updated_at`, and reconciles list membership through `useIssueView`.

## Notifications

`App\Notifications\Projects` covers assignment, ownership, mentions, comments, status changes, ready-to-complete, completion conflicts, blockers, hand-offs, team added, due soon / overdue, cycle started and issues created from email. They use the database, broadcast and push channels like Mail notifications, under the `PushTopic::Teams` topic (off by default per user). Workstream events notify only that team's participants and the issue owner.

## Mail integration

A conversation and an issue can be linked from either side (`IssueConversationLink`, kinds `linked` and `created_from`). Linking never exposes mail content: an issue lists only the linked conversations the viewer can open and reports the rest as `hidden_link_count`; a conversation lists only the issues the viewer can see. Sharing an excerpt or an attachment into an issue is a separate, explicit action (`IssueSharedContent`) that requires the actor to be able to read the conversation and comment on the issue; excerpts are sanitised and inline images stripped, attachments are copied into `issue_attachments`. Both are audited.

## Automations

Rules have a subject: `conversation` (scoped by inbox) or `issue` (scoped by product and/or team). Issue triggers: `issue_created`, `issue_assigned`, `team_added`, `workstream_status_changed`, `overall_status_changed`, `issue_completed`, `dependency_resolved`, `due_date_approaching`, `issue_overdue`, `comment_added`, `cycle_started`, `cycle_completed`. Conversation rules gain `create_issue` and `link_issue` actions; issue rules can set status, priority, owner, add teams, assign a team member (round robin or least busy), set a team's status, add or remove labels, comment, follow, move to a project and notify people.

`RunIssueAutomations` turns project domain events into triggers on the `automations` queue; the echo guard skips events the engine caused itself and `AutomationEngine::MAX_DEPTH` stops chains. `projects:due-dates` fires the two scheduled triggers at most once per rule, issue and day. See [automations.md](automations.md) for the JSON shapes.

## Reporting

`Reporting` produces organization, product, project, team and cross-team metrics. Issue metrics always count distinct issues; workstream metrics are labelled as such so an issue worked by three teams is one issue in throughput and three in workload.

## Operations

| Command              | Schedule | Purpose                                                                                                        |
| -------------------- | -------- | -------------------------------------------------------------------------------------------------------------- |
| `projects:cycles`    | hourly   | start due cycles, complete finished ones (carrying over open work), schedule upcoming cycles per team settings |
| `projects:due-dates` | hourly   | due-date automation triggers and the built-in due tomorrow / overdue reminders                                 |
| `tandem:prune`       | daily    | also prunes `recent_views` and permanently deletes issues trashed longer than 30 days                          |

Queues: `automations`, `notifications`, `broadcasts` and `default` (project progress recomputes). Horizon already lists them.

## Demo data

`php artisan migrate:fresh --seed` seeds Northwind Labs (login `demo@tandem.test` / `password`) with the Support, Sales and Finance mail setup plus Engineering, Design and QA teams, the Portivio Frontier (`FRONT`) and Tandem (`TANDEM`) products, the Reporting Redesign, CRM Improvements (private) and Gmail Integration projects, an initiative, releases, cycles, saved views, issue automations and about forty issues. `FRONT-1` is the showcase: Engineering and Design are done, QA was blocked on Engineering and received the hand-off. `FRONT-5` was completed with an override while QA was still open, `NWL-1` is an organization-level issue and `FRONT-3` was created from Ada's CSV export email.

## Navigation

The rail on the left switches between the three areas of the app: Mail, Projects and Settings. Each item is labelled and returns you to the page you last had open in that area (remembered per organization in `sessionStorage`, see `resources/js/lib/navigation.ts`). Notifications and the account menu (profile, appearance, shortcuts, log out) sit at the bottom of the rail; `mod+\` or the toggle under the brand mark hides the sidebar.

Every sidebar shares one frame (`components/shell`): the workspace switcher (organizations, organization settings, invitations), the area's primary action, a search row that opens the command palette, then `SidebarSection`s of `SidebarRow`s. The projects sidebar lists your work (My issues, Triage, Issues, Reports), favorites, your teams with their active cycle, active projects, products with their modules, and saved views; every entity appears once, and recent items live in the command palette. Teams only appear once they do project work (a product, a project, a workstream or cycles), so mail-only teams stay out. `projectsNav` is the shared prop behind it (`App\Services\Projects\ProjectsNavigation`).

Every projects page renders `WorkspaceHeader`. Its breadcrumb trail starts at the section the sidebar links to: `Products › Portivio Frontier › Modules › Exports`, `Projects › Reporting Redesign`, `Teams › Engineering › Cycles › Dashboards beta`, `Reports › Engineering`. Issues show `Projects › project › FRONT-1` when they belong to a project, `Products › product › FRONT-1` otherwise, and `Issues › NWL-1` for organization-level issues; a parent issue is inserted before the identifier.

## Keyboard

`c` new issue, `/` search, `mod+k` command palette, `mod+\` toggle sidebar, `g i` my issues, `g p` projects, `g r` products, `g t` teams, `g m` mail, `g s` settings, `shift+/` cheat sheet. On lists: `j`/`k` move, `enter` open, `x` select, `s`/`a`/`p`/`l`/`d`/`e` set status/assignee/priority/label/due date/estimate, `shift+p`/`shift+t`/`shift+c` move to project/team/cycle. The cheat sheet lists whatever is registered on the current page.
