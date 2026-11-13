<?php

namespace Database\Seeders;

use App\Enums\Automations\AutomationTrigger;
use App\Enums\Projects\InitiativeStatus;
use App\Enums\Projects\IssueLinkKind;
use App\Enums\Projects\IssuePriority;
use App\Enums\Projects\IssueRelationType;
use App\Enums\Projects\IssueViewScope;
use App\Enums\Projects\ReleaseStatus;
use App\Enums\Projects\StatusCategory;
use App\Enums\TeamRole;
use App\Models\Mail\Conversation;
use App\Models\Organization;
use App\Models\Projects\Cycle;
use App\Models\Projects\Initiative;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueLabel;
use App\Models\Projects\IssueView;
use App\Models\Projects\IssueWorkstream;
use App\Models\Projects\Milestone;
use App\Models\Projects\Module;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Projects\Release;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\ConversationIssueLinks;
use App\Services\Projects\CycleScheduler;
use App\Services\Projects\DependencyGraph;
use App\Services\Projects\Favorites;
use App\Services\Projects\IssueActions;
use App\Services\Projects\IssueCommentService;
use App\Services\Projects\ProductActions;
use App\Services\Projects\ProjectActions;
use App\Services\Projects\ProjectProgress;
use App\Services\Projects\WorkflowActions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * Extends the Northwind Labs demo workspace with products, projects, teams, cycles and
 * ~40 issues that exercise every cross-team rule: multi-team issues, blocked workstreams,
 * hand-offs, an overridden completion, sub-issues, an org-level issue and email-linked work.
 * Runs after DemoSeeder and relies on its users, teams and conversations.
 */
class DemoProjectsSeeder extends Seeder
{
    protected Organization $organization;

    /** @var array<string, User> */
    protected array $users = [];

    /** @var array<string, Team> */
    protected array $teams = [];

    /** @var array<string, Product> */
    protected array $products = [];

    /** @var array<string, Project> */
    protected array $projects = [];

    /** @var array<string, Module> */
    protected array $modules = [];

    /** @var array<string, IssueLabel> */
    protected array $labels = [];

    /** @var array<string, Cycle> */
    protected array $cycles = [];

    /** @var array<string, Issue> */
    protected array $issues = [];

    protected CarbonImmutable $now;

    public function __construct(
        protected IssueActions $issueActions,
        protected ProductActions $productActions,
        protected ProjectActions $projectActions,
        protected WorkflowActions $workflows,
        protected CycleScheduler $cycleScheduler,
        protected DependencyGraph $graph,
        protected IssueCommentService $comments,
        protected ConversationIssueLinks $links,
        protected Favorites $favorites,
        protected ProjectProgress $progress,
    ) {}

    public function run(): void
    {
        Notification::fake();
        Queue::fake();

        $this->now = CarbonImmutable::now();
        $this->organization = Organization::query()->where('slug', 'northwind-labs')->firstOrFail();
        $this->organization->forceFill(['issue_key' => 'NWL'])->save();

        foreach (['jamie' => 'demo@tandem.test', 'sam' => 'sam@northwind.test', 'priya' => 'priya@northwind.test', 'lars' => 'lars@northwind.test'] as $key => $email) {
            $this->users[$key] = User::query()->where('email', $email)->firstOrFail();
        }

        $this->seedTeams();
        $this->seedLabels();
        $this->seedProducts();
        $this->seedProjects();
        $this->seedCycles();
        $this->seedIssues();
        $this->seedEmailLinks();
        $this->seedViewsAndFavorites();
        $this->seedAutomations();

        foreach ($this->projects as $project) {
            $this->progress->recompute($project);
        }

        foreach ($this->cycles as $cycle) {
            $this->cycleScheduler->refreshCounters($cycle);
        }
    }

    protected function seedTeams(): void
    {
        $this->teams['support'] = Team::query()->where('organization_id', $this->organization->id)->where('name', 'Support')->firstOrFail();

        $this->teams['engineering'] = Team::create(['organization_id' => $this->organization->id, 'name' => 'Engineering', 'color' => 'violet', 'icon' => 'code', 'description' => 'Ships the product.', 'estimate_scale' => 'fibonacci', 'cycle_settings' => ['enabled' => true, 'length_weeks' => 2, 'cooldown_weeks' => 0, 'start_weekday' => 1, 'upcoming_count' => 1, 'auto_carry_over' => true]]);
        $this->teams['design'] = Team::create(['organization_id' => $this->organization->id, 'name' => 'Design', 'color' => 'rose', 'icon' => 'pen-tool', 'description' => 'Makes it make sense.', 'estimate_scale' => 'tshirt']);
        $this->teams['qa'] = Team::create(['organization_id' => $this->organization->id, 'name' => 'QA', 'color' => 'sky', 'icon' => 'shield-check', 'description' => 'Breaks it before customers do.', 'cycle_settings' => ['enabled' => true, 'length_weeks' => 2, 'cooldown_weeks' => 0, 'start_weekday' => 1, 'upcoming_count' => 1, 'auto_carry_over' => true]]);

        $this->teams['engineering']->members()->attach([
            $this->users['sam']->id => ['role' => TeamRole::Lead->value],
            $this->users['priya']->id => ['role' => TeamRole::Member->value],
            $this->users['lars']->id => ['role' => TeamRole::Member->value],
        ]);
        $this->teams['design']->members()->attach([
            $this->users['priya']->id => ['role' => TeamRole::Lead->value],
            $this->users['jamie']->id => ['role' => TeamRole::Member->value],
        ]);
        $this->teams['qa']->members()->attach([
            $this->users['lars']->id => ['role' => TeamRole::Lead->value],
            $this->users['sam']->id => ['role' => TeamRole::Member->value],
        ]);

        foreach (['engineering', 'design', 'qa', 'support'] as $key) {
            $this->workflows->forTeam($this->teams[$key]);
        }

        $this->workflows->defaultIssueWorkflow($this->organization);
    }

    protected function seedLabels(): void
    {
        foreach ([
            ['Bug', 'rose', 'Something is broken.'],
            ['Improvement', 'violet', 'Makes an existing thing better.'],
            ['Tech debt', 'amber', 'We owe the codebase this one.'],
            ['Customer-reported', 'teal', 'Came in through the inbox.'],
            ['Needs design', 'pink', 'Blocked on a design decision.'],
        ] as [$name, $color, $description]) {
            $this->labels[$name] = IssueLabel::query()->create(['organization_id' => $this->organization->id, 'scope_key' => 'org', 'name' => $name, 'color' => $color, 'description' => $description]);
        }
    }

    protected function seedProducts(): void
    {
        $sam = $this->users['sam'];
        $jamie = $this->users['jamie'];

        $this->products['FRONT'] = $this->productActions->create($this->organization, [
            'name' => 'Portivio Frontier',
            'key' => 'FRONT',
            'description' => 'The customer-facing analytics suite: reporting, CRM and integrations.',
            'icon' => 'compass',
            'color' => 'violet',
            'owner_id' => $sam->id,
            'team_ids' => [$this->teams['engineering']->id, $this->teams['design']->id, $this->teams['qa']->id],
            'member_ids' => [$jamie->id, $this->users['priya']->id, $this->users['lars']->id],
        ], $sam);

        $this->products['TANDEM'] = $this->productActions->create($this->organization, [
            'name' => 'Tandem',
            'key' => 'TANDEM',
            'description' => 'Shared inbox, projects and automations in one place.',
            'icon' => 'mail',
            'color' => 'teal',
            'owner_id' => $jamie->id,
            'team_ids' => [$this->teams['engineering']->id, $this->teams['qa']->id, $this->teams['support']->id],
            'member_ids' => [$sam->id, $this->users['priya']->id],
        ], $jamie);

        foreach ([
            ['FRONT', 'Reporting', null, 'bar-chart', 'violet', ['engineering', 'design']],
            ['FRONT', 'Exports', 'Reporting', 'download', 'violet', ['engineering']],
            ['FRONT', 'Dashboards', 'Reporting', 'layout-dashboard', 'violet', ['design']],
            ['FRONT', 'CRM', null, 'users', 'amber', ['engineering']],
            ['FRONT', 'Integrations', null, 'plug', 'sky', ['engineering', 'qa']],
            ['FRONT', 'Gmail', 'Integrations', 'mail', 'sky', ['engineering']],
            ['FRONT', 'Outlook', 'Integrations', 'mail', 'sky', ['engineering']],
            ['TANDEM', 'Mail', null, 'inbox', 'teal', ['engineering', 'support']],
            ['TANDEM', 'Projects', null, 'kanban', 'teal', ['engineering', 'design']],
            ['TANDEM', 'Automations', null, 'workflow', 'teal', ['engineering']],
        ] as [$product, $name, $parent, $icon, $color, $teams]) {
            $this->modules[$name] = $this->productActions->createModule($this->products[$product], [
                'name' => $name,
                'icon' => $icon,
                'color' => $color,
                'parent_id' => $parent ? $this->modules[$parent]->id : null,
                'team_ids' => array_map(fn (string $key) => $this->teams[$key]->id, $teams),
            ], $sam);
        }

        $this->labels['Design review'] = IssueLabel::query()->create(['organization_id' => $this->organization->id, 'product_id' => $this->products['FRONT']->id, 'scope_key' => 'product:'.$this->products['FRONT']->id, 'name' => 'Design review', 'color' => 'rose']);

        Release::query()->create(['product_id' => $this->products['FRONT']->id, 'name' => 'Frontier 2026.1', 'version' => '2026.1', 'status' => ReleaseStatus::InProgress, 'planned_at' => $this->now->addWeeks(3)->toDateString(), 'notes_html' => '<p>The reporting redesign, CSV export fixes and the first cut of Gmail sync.</p>', 'created_by_id' => $sam->id]);
        Release::query()->create(['product_id' => $this->products['FRONT']->id, 'name' => 'Frontier 2025.4', 'version' => '2025.4', 'status' => ReleaseStatus::Released, 'planned_at' => $this->now->subWeeks(5)->toDateString(), 'released_at' => $this->now->subWeeks(5)->addDays(2), 'notes_html' => '<p>Dark mode, scheduled sends and the new invoice layout.</p>', 'created_by_id' => $sam->id]);
        Release::query()->create(['product_id' => $this->products['TANDEM']->id, 'name' => 'Tandem 1.2', 'version' => '1.2.0', 'status' => ReleaseStatus::Planned, 'planned_at' => $this->now->addWeeks(6)->toDateString(), 'created_by_id' => $jamie->id]);
    }

    protected function seedProjects(): void
    {
        $sam = $this->users['sam'];
        $jamie = $this->users['jamie'];

        $this->projects['reporting'] = $this->projectActions->create($this->organization, [
            'name' => 'Reporting Redesign',
            'product_id' => $this->products['FRONT']->id,
            'description_html' => '<p>Rebuild the reporting area around saved reports, faster exports and dashboards people actually open. Design leads the first milestone, Engineering the second, QA signs off before GA.</p>',
            'icon' => 'bar-chart',
            'color' => 'violet',
            'lead_id' => $sam->id,
            'status' => 'in_progress',
            'health' => 'on_track',
            'priority' => 3,
            'start_date' => $this->now->subWeeks(4)->toDateString(),
            'target_date' => $this->now->addWeeks(5)->toDateString(),
            'team_ids' => [$this->teams['engineering']->id, $this->teams['design']->id, $this->teams['qa']->id],
            'module_ids' => [$this->modules['Reporting']->id, $this->modules['Exports']->id, $this->modules['Dashboards']->id],
            'member_ids' => [$this->users['priya']->id, $this->users['lars']->id],
        ], $sam);

        $this->projects['crm'] = $this->projectActions->create($this->organization, [
            'name' => 'CRM Improvements',
            'product_id' => $this->products['FRONT']->id,
            'description_html' => '<p>Private until pricing is settled: deal stages, pipeline forecasting and the Sales inbox hand-off. Only the project members can see these issues.</p>',
            'icon' => 'users',
            'color' => 'amber',
            'lead_id' => $sam->id,
            'status' => 'planned',
            'health' => 'at_risk',
            'priority' => 2,
            'visibility' => 'private',
            'start_date' => $this->now->addWeeks(2)->toDateString(),
            'target_date' => $this->now->addWeeks(12)->toDateString(),
            'team_ids' => [$this->teams['engineering']->id],
            'module_ids' => [$this->modules['CRM']->id],
            'member_ids' => [$jamie->id],
        ], $sam);

        $this->projects['gmail'] = $this->projectActions->create($this->organization, [
            'name' => 'Gmail Integration',
            'product_id' => $this->products['TANDEM']->id,
            'description_html' => '<p>Two-way Gmail sync for personal inboxes: OAuth, history-based delta sync, push notifications and a QA pass on threading.</p>',
            'icon' => 'mail',
            'color' => 'teal',
            'lead_id' => $jamie->id,
            'status' => 'in_progress',
            'health' => 'on_track',
            'priority' => 3,
            'start_date' => $this->now->subWeeks(2)->toDateString(),
            'target_date' => $this->now->addWeeks(4)->toDateString(),
            'team_ids' => [$this->teams['engineering']->id, $this->teams['qa']->id],
            'module_ids' => [$this->modules['Mail']->id],
            'member_ids' => [$sam->id, $this->users['lars']->id],
        ], $jamie);

        foreach ([
            ['reporting', 'Design signed off', $this->now->subWeeks(1), 'completed'],
            ['reporting', 'Beta to design partners', $this->now->addWeeks(2), 'active'],
            ['reporting', 'General availability', $this->now->addWeeks(5), 'planned'],
            ['gmail', 'OAuth and first sync', $this->now->addWeeks(1), 'active'],
            ['gmail', 'Push notifications', $this->now->addWeeks(4), 'planned'],
            ['crm', 'Pricing decided', $this->now->addWeeks(3), 'planned'],
        ] as [$project, $name, $date, $status]) {
            $milestone = $this->projectActions->createMilestone($this->projects[$project], ['name' => $name, 'target_date' => $date->toDateString(), 'status' => $status], $sam);

            if ($status === 'completed') {
                $milestone->forceFill(['completed_at' => $date])->save();
            }

            $this->milestones[$name] = $milestone;
        }

        $initiative = Initiative::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Enterprise readiness',
            'description_html' => '<p>Everything the 340-seat deals keep asking for: reporting they trust, SSO, and mail that syncs both ways.</p>',
            'color' => 'violet',
            'owner_id' => $jamie->id,
            'status' => InitiativeStatus::Active,
            'start_date' => $this->now->subWeeks(4)->toDateString(),
            'target_date' => $this->now->addWeeks(12)->toDateString(),
        ]);
        $initiative->projects()->attach([$this->projects['reporting']->id => ['position' => 1], $this->projects['gmail']->id => ['position' => 2]]);
    }

    /** @var array<string, Milestone> */
    protected array $milestones = [];

    protected function seedCycles(): void
    {
        $engineering = $this->teams['engineering'];
        $qa = $this->teams['qa'];
        $monday = $this->now->startOfWeek();

        $previous = $this->cycleScheduler->create($engineering, ['name' => 'Exports and paginators', 'goals' => 'Close the CSV export bugs and land the report paginator.', 'starts_at' => $monday->subWeeks(3), 'ends_at' => $monday->subWeeks(1)], $this->users['sam']);
        $previous->forceFill(['status' => 'completed', 'started_at' => $monday->subWeeks(3), 'completed_at' => $monday->subWeeks(1)])->save();

        $this->cycles['current'] = $this->cycleScheduler->create($engineering, ['name' => 'Dashboards beta', 'goals' => 'Ship the dashboard grid to design partners and finish the Gmail OAuth flow.', 'starts_at' => $monday->subWeek(), 'ends_at' => $monday->addWeek()], $this->users['sam']);
        $this->cycleScheduler->start($this->cycles['current'], $this->users['sam']);
        $this->cycles['current']->forceFill(['started_at' => $monday->subWeek()])->save();

        $this->cycles['next'] = $this->cycleScheduler->create($engineering, ['name' => 'GA hardening', 'starts_at' => $monday->addWeek(), 'ends_at' => $monday->addWeeks(3)], $this->users['sam']);
        $this->cycles['previous'] = $previous;

        $this->cycles['qa'] = $this->cycleScheduler->create($qa, ['name' => 'Beta sign-off', 'goals' => 'Regression pass on reporting and threading.', 'starts_at' => $monday->subWeek(), 'ends_at' => $monday->addWeek()], $this->users['lars']);
        $this->cycleScheduler->start($this->cycles['qa'], $this->users['lars']);
    }

    /**
     * Every issue in one place. Keys: ref, product (key or null for org-level), project, title, body, priority,
     * owner, lead, teams [team => [status, assignees, estimate, cycle, responsibility, required]], labels,
     * modules, milestone, due (days from now), age (days ago), status (for issues without teams),
     * parent (ref), blocks (refs), depends (team => team), override, comments [[author, html]].
     *
     * @return array<int, array<string, mixed>>
     */
    protected function issueSpecs(): array
    {
        return [
            // --- FRONT-1: the showcase cross-team issue. Engineering done, Design done, QA blocked on Engineering until it completed, now handed off.
            ['ref' => 'saved-reports', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Saved reports: pin, rename and share a report', 'priority' => 'high', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 18, 'due' => 6, 'milestone' => 'Beta to design partners', 'modules' => ['Reporting'], 'labels' => ['Improvement'], 'estimate' => 8,
                'body' => '<p>Users rebuild the same report every Monday. Let them save it, pin it to the sidebar and share it with their team.</p><ul data-type="taskList"><li data-type="taskItem" data-checked="true">Data model and API</li><li data-type="taskItem" data-checked="true">Sidebar pinning</li><li data-type="taskItem" data-checked="false">Share dialog with permissions</li></ul>',
                'teams' => [
                    'engineering' => ['status' => 'completed', 'assignees' => ['priya'], 'estimate' => 5, 'cycle' => 'previous', 'responsibility' => 'API, persistence and the share dialog'],
                    'design' => ['status' => 'completed', 'assignees' => ['priya'], 'estimate' => 2, 'responsibility' => 'Share dialog and empty states'],
                    'qa' => ['status' => 'started', 'assignees' => ['lars'], 'estimate' => 1, 'cycle' => 'qa', 'responsibility' => 'Regression on permissions and sharing'],
                ],
                'depends' => ['qa' => 'engineering'],
                'comments' => [['sam', '<p>Engineering is done on this one, handing over to <span data-type="mention" data-id="{lars}">@Lars Vermeulen</span> for the permissions pass.</p>'], ['lars', '<p>Started the regression run. Sharing with a viewer-only teammate hides the edit button as expected.</p>']],
            ],
            // --- FRONT-2: blocked QA workstream, Engineering still in progress.
            ['ref' => 'dashboard-grid', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Dashboard grid with drag-to-resize widgets', 'priority' => 'urgent', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 12, 'due' => 9, 'milestone' => 'Beta to design partners', 'modules' => ['Dashboards'], 'labels' => ['Improvement'],
                'body' => '<p>The dashboard is a fixed 3-column list today. Make it a grid where widgets can be dragged, resized and stacked, persisted per user.</p>',
                'teams' => [
                    'design' => ['status' => 'completed', 'assignees' => ['priya'], 'estimate' => 3, 'responsibility' => 'Grid spec, breakpoints and empty state'],
                    'engineering' => ['status' => 'started', 'assignees' => ['lars', 'priya'], 'estimate' => 8, 'cycle' => 'current', 'responsibility' => 'Grid layout engine and persistence'],
                    'qa' => ['status' => 'unstarted', 'assignees' => ['lars'], 'estimate' => 2, 'cycle' => 'qa', 'responsibility' => 'Cross-browser drag and keyboard resize'],
                ],
                'depends' => ['qa' => 'engineering'],
                'comments' => [['priya', '<p>Spec is in Figma, including the keyboard resize behaviour. Pinged <span data-type="mention" data-id="{lars}">@Lars Vermeulen</span> for the QA plan.</p>']],
            ],
            // --- FRONT-3: email-linked bug, created from the CSV conversation; Engineering done, overall completed.
            ['ref' => 'csv-last-row', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'CSV export drops the last row on reports over 1,000 rows', 'priority' => 'high', 'owner' => 'priya', 'lead' => 'engineering', 'age' => 10, 'completed' => 6, 'modules' => ['Exports'], 'labels' => ['Bug', 'Customer-reported'],
                'body' => '<p>Reported by Ada via Support. Off-by-one in the paginator when the row count is an exact multiple of the page size.</p>',
                'teams' => ['engineering' => ['status' => 'completed', 'assignees' => ['priya'], 'estimate' => 2, 'cycle' => 'previous']],
                'comments' => [['priya', '<p>Fixed in the paginator, added a regression test with exactly 1,000 rows.</p>']],
            ],
            // --- FRONT-4: SSO request linked to the Grace Hopper thread; backlog, design + engineering.
            ['ref' => 'saml-sso', 'product' => 'FRONT', 'project' => null, 'title' => 'SAML single sign-on via Okta', 'priority' => 'medium', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 9, 'due' => 40, 'modules' => ['Integrations'], 'labels' => ['Customer-reported', 'Needs design'],
                'body' => '<p>Grace at cobol.navy is rolling out to 340 people and wants Okta SSO. Design partner offer on the table.</p>',
                'teams' => ['engineering' => ['status' => 'backlog', 'estimate' => 13], 'design' => ['status' => 'backlog', 'required' => false, 'responsibility' => 'Settings screen for identity providers']],
            ],
            // --- FRONT-5: overridden completion. QA workstream still open but the owner closed it.
            ['ref' => 'invoice-layout', 'product' => 'FRONT', 'project' => null, 'title' => 'Invoice PDF attached twice in the billing email', 'priority' => 'low', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 20, 'completed' => 3, 'labels' => ['Bug', 'Customer-reported'], 'modules' => ['CRM'],
                'body' => '<p>Tim flagged the PDF showing up twice. Root cause was the retry wrapper re-attaching on the second attempt.</p>',
                'teams' => ['engineering' => ['status' => 'completed', 'assignees' => ['sam'], 'estimate' => 1, 'cycle' => 'previous'], 'qa' => ['status' => 'unstarted', 'assignees' => ['lars'], 'estimate' => 1]],
                'override' => 'Verified in production by the customer; QA pass is not worth a cycle slot.',
            ],
            // --- parent with sub-issues
            ['ref' => 'report-builder', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Report builder v2', 'priority' => 'high', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 16, 'due' => 30, 'milestone' => 'General availability', 'modules' => ['Reporting'], 'labels' => ['Improvement'],
                'body' => '<p>Umbrella for the new report builder. Sub-issues track the pieces.</p>',
                'teams' => ['engineering' => ['status' => 'started', 'assignees' => ['sam'], 'estimate' => 13, 'cycle' => 'current'], 'design' => ['status' => 'started', 'assignees' => ['priya'], 'estimate' => 5]],
            ],
            ['ref' => 'rb-filters', 'product' => 'FRONT', 'project' => 'reporting', 'parent' => 'report-builder', 'title' => 'Filter rows with AND/OR groups', 'priority' => 'high', 'owner' => 'priya', 'lead' => 'engineering', 'age' => 14, 'due' => 12, 'modules' => ['Reporting'],
                'teams' => ['engineering' => ['status' => 'started', 'assignees' => ['priya'], 'estimate' => 5, 'cycle' => 'current']],
            ],
            ['ref' => 'rb-grouping', 'product' => 'FRONT', 'project' => 'reporting', 'parent' => 'report-builder', 'title' => 'Group by any dimension with subtotals', 'priority' => 'medium', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 14, 'due' => 18, 'modules' => ['Reporting'],
                'teams' => ['engineering' => ['status' => 'unstarted', 'assignees' => ['lars'], 'estimate' => 5, 'cycle' => 'next']],
            ],
            ['ref' => 'rb-chart-picker', 'product' => 'FRONT', 'project' => 'reporting', 'parent' => 'report-builder', 'title' => 'Chart type picker with live preview', 'priority' => 'medium', 'owner' => 'priya', 'lead' => 'design', 'age' => 13, 'due' => 14, 'modules' => ['Dashboards'], 'labels' => ['Design review'],
                'teams' => ['design' => ['status' => 'started', 'assignees' => ['priya'], 'estimate' => 3], 'engineering' => ['status' => 'unstarted', 'estimate' => 3, 'cycle' => 'next']],
                'depends' => ['engineering' => 'design'],
            ],
            // --- blocked by another issue (issue-level relation)
            ['ref' => 'export-scheduler', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Schedule a report export every Monday', 'priority' => 'medium', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 8, 'due' => 20, 'modules' => ['Exports'], 'labels' => ['Improvement'],
                'body' => '<p>Blocked on saved reports: a schedule needs something saved to run.</p>',
                'teams' => ['engineering' => ['status' => 'unstarted', 'assignees' => ['lars'], 'estimate' => 5, 'cycle' => 'next']],
            ],
            ['ref' => 'export-xlsx', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Export to XLSX with number formats preserved', 'priority' => 'low', 'owner' => 'lars', 'lead' => 'engineering', 'age' => 7, 'modules' => ['Exports'], 'labels' => ['Improvement'],
                'teams' => ['engineering' => ['status' => 'backlog', 'estimate' => 3]],
            ],
            ['ref' => 'paginator-debt', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Replace the hand-rolled report paginator with cursor pagination', 'priority' => 'low', 'owner' => 'priya', 'lead' => 'engineering', 'age' => 6, 'modules' => ['Reporting'], 'labels' => ['Tech debt'],
                'teams' => ['engineering' => ['status' => 'backlog', 'estimate' => 3]],
            ],
            ['ref' => 'dashboard-empty', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Dashboard empty state and first-run tour', 'priority' => 'medium', 'owner' => 'priya', 'lead' => 'design', 'age' => 11, 'due' => 8, 'milestone' => 'Beta to design partners', 'modules' => ['Dashboards'],
                'teams' => ['design' => ['status' => 'completed', 'assignees' => ['priya'], 'estimate' => 2], 'engineering' => ['status' => 'started', 'assignees' => ['priya'], 'estimate' => 2, 'cycle' => 'current']],
            ],
            ['ref' => 'dashboard-share', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Share a dashboard with a public read-only link', 'priority' => 'medium', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 5, 'due' => 25, 'milestone' => 'General availability', 'modules' => ['Dashboards'], 'labels' => ['Needs design'],
                'teams' => ['engineering' => ['status' => 'backlog', 'estimate' => 5], 'design' => ['status' => 'unstarted', 'assignees' => ['priya'], 'estimate' => 2]],
            ],
            ['ref' => 'report-perf', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Monthly usage report takes 14s for large accounts', 'priority' => 'urgent', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 4, 'due' => 2, 'modules' => ['Reporting'], 'labels' => ['Bug'],
                'body' => '<p>Profiling shows the N+1 on the account dimension. Needs a pre-aggregated table.</p>',
                'teams' => ['engineering' => ['status' => 'started', 'assignees' => ['sam'], 'estimate' => 5, 'cycle' => 'current']],
                'comments' => [['sam', '<p>Pre-aggregation lands tomorrow; keeping this urgent until the p95 is under 2s.</p>']],
            ],
            ['ref' => 'report-timezone', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Report date ranges ignore the workspace timezone', 'priority' => 'high', 'owner' => 'priya', 'lead' => 'engineering', 'age' => 15, 'completed' => 9, 'modules' => ['Reporting'], 'labels' => ['Bug'],
                'teams' => ['engineering' => ['status' => 'completed', 'assignees' => ['priya'], 'estimate' => 2, 'cycle' => 'previous'], 'qa' => ['status' => 'completed', 'assignees' => ['lars'], 'estimate' => 1]],
            ],
            ['ref' => 'report-print', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Print stylesheet for reports', 'priority' => 'low', 'owner' => 'priya', 'lead' => 'design', 'age' => 22, 'completed' => 16, 'modules' => ['Reporting'],
                'teams' => ['design' => ['status' => 'completed', 'assignees' => ['priya'], 'estimate' => 1], 'engineering' => ['status' => 'completed', 'assignees' => ['lars'], 'estimate' => 1, 'cycle' => 'previous']],
            ],
            ['ref' => 'report-canceled', 'product' => 'FRONT', 'project' => 'reporting', 'title' => 'Export reports to Google Sheets', 'priority' => 'low', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 19, 'canceled' => 12, 'modules' => ['Exports'],
                'body' => '<p>Dropped for GA. XLSX export covers the need.</p>',
                'teams' => ['engineering' => ['status' => 'canceled', 'estimate' => 3]],
            ],
            // --- CRM (private project)
            ['ref' => 'deal-stages', 'product' => 'FRONT', 'project' => 'crm', 'title' => 'Custom deal stages per pipeline', 'priority' => 'high', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 6, 'due' => 35, 'milestone' => 'Pricing decided', 'modules' => ['CRM'],
                'teams' => ['engineering' => ['status' => 'backlog', 'estimate' => 8]],
            ],
            ['ref' => 'pipeline-forecast', 'product' => 'FRONT', 'project' => 'crm', 'title' => 'Weighted pipeline forecast', 'priority' => 'medium', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 6, 'due' => 50, 'modules' => ['CRM'],
                'teams' => ['engineering' => ['status' => 'backlog', 'estimate' => 8], 'design' => ['status' => 'backlog', 'required' => false]],
            ],
            ['ref' => 'sales-handoff', 'product' => 'FRONT', 'project' => 'crm', 'title' => 'Hand a Sales inbox conversation off to a deal', 'priority' => 'medium', 'owner' => 'jamie', 'lead' => 'engineering', 'age' => 5, 'modules' => ['CRM'],
                'teams' => ['engineering' => ['status' => 'backlog', 'estimate' => 5]],
            ],
            // --- Integrations without a project
            ['ref' => 'outlook-calendar', 'product' => 'FRONT', 'project' => null, 'title' => 'Outlook calendar availability in the scheduler', 'priority' => 'low', 'owner' => 'lars', 'lead' => 'engineering', 'age' => 30, 'modules' => ['Outlook'],
                'teams' => ['engineering' => ['status' => 'backlog', 'estimate' => 8]],
            ],
            ['ref' => 'gmail-labels-front', 'product' => 'FRONT', 'project' => null, 'title' => 'Map Gmail labels to Frontier tags', 'priority' => 'medium', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 3, 'due' => 15, 'modules' => ['Gmail'], 'labels' => ['Improvement'],
                'teams' => ['engineering' => ['status' => 'unstarted', 'assignees' => ['priya'], 'estimate' => 3, 'cycle' => 'next']],
            ],
            // --- TANDEM / Gmail Integration
            ['ref' => 'gmail-oauth', 'product' => 'TANDEM', 'project' => 'gmail', 'title' => 'Google OAuth consent flow with incremental scopes', 'priority' => 'urgent', 'owner' => 'jamie', 'lead' => 'engineering', 'age' => 13, 'due' => 5, 'milestone' => 'OAuth and first sync', 'modules' => ['Mail'],
                'body' => '<p>Ask for read scopes first, upgrade to send when the user composes. Store refresh tokens encrypted.</p>',
                'teams' => ['engineering' => ['status' => 'started', 'assignees' => ['sam'], 'estimate' => 5, 'cycle' => 'current'], 'qa' => ['status' => 'unstarted', 'assignees' => ['lars'], 'estimate' => 2, 'cycle' => 'qa']],
                'depends' => ['qa' => 'engineering'],
            ],
            ['ref' => 'gmail-history', 'product' => 'TANDEM', 'project' => 'gmail', 'title' => 'History-based delta sync', 'priority' => 'high', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 12, 'due' => 7, 'milestone' => 'OAuth and first sync', 'modules' => ['Mail'],
                'teams' => ['engineering' => ['status' => 'started', 'assignees' => ['lars'], 'estimate' => 8, 'cycle' => 'current']],
            ],
            ['ref' => 'gmail-threading', 'product' => 'TANDEM', 'project' => 'gmail', 'title' => 'Scheduled sends go out an hour early after DST', 'priority' => 'high', 'owner' => 'priya', 'lead' => 'engineering', 'age' => 2, 'due' => 3, 'modules' => ['Mail'], 'labels' => ['Bug', 'Customer-reported'],
                'body' => '<p>Katherine reported sends leaving an hour early for the London team since the clocks changed.</p>',
                'teams' => ['engineering' => ['status' => 'unstarted', 'assignees' => ['priya'], 'estimate' => 2, 'cycle' => 'current'], 'qa' => ['status' => 'unstarted', 'estimate' => 1, 'cycle' => 'qa']],
            ],
            ['ref' => 'gmail-push', 'product' => 'TANDEM', 'project' => 'gmail', 'title' => 'Pub/Sub push notifications for new mail', 'priority' => 'medium', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 11, 'due' => 27, 'milestone' => 'Push notifications', 'modules' => ['Mail'],
                'teams' => ['engineering' => ['status' => 'unstarted', 'estimate' => 5, 'cycle' => 'next']],
            ],
            ['ref' => 'gmail-attachments', 'product' => 'TANDEM', 'project' => 'gmail', 'title' => 'Stream large attachments instead of buffering', 'priority' => 'medium', 'owner' => 'lars', 'lead' => 'engineering', 'age' => 9, 'completed' => 4, 'modules' => ['Mail'], 'labels' => ['Tech debt'],
                'teams' => ['engineering' => ['status' => 'completed', 'assignees' => ['lars'], 'estimate' => 3, 'cycle' => 'previous']],
            ],
            ['ref' => 'gmail-reconnect', 'product' => 'TANDEM', 'project' => 'gmail', 'title' => 'Reconnect banner when the refresh token is revoked', 'priority' => 'medium', 'owner' => 'jamie', 'lead' => 'design', 'age' => 8, 'due' => 10, 'modules' => ['Mail'],
                'teams' => ['design' => ['status' => 'completed', 'assignees' => ['priya'], 'estimate' => 1], 'engineering' => ['status' => 'unstarted', 'assignees' => ['sam'], 'estimate' => 2, 'cycle' => 'current'], 'support' => ['status' => 'unstarted', 'required' => false, 'responsibility' => 'Help-centre article']],
            ],
            ['ref' => 'gmail-threads-qa', 'product' => 'TANDEM', 'project' => 'gmail', 'title' => 'Threading regression suite for Gmail conversations', 'priority' => 'medium', 'owner' => 'lars', 'lead' => 'qa', 'age' => 7, 'due' => 12, 'modules' => ['Mail'],
                'teams' => ['qa' => ['status' => 'started', 'assignees' => ['lars'], 'estimate' => 3, 'cycle' => 'qa']],
            ],
            // --- TANDEM / Projects and Automations
            ['ref' => 'board-swimlanes', 'product' => 'TANDEM', 'project' => null, 'title' => 'Board swimlanes by assignee', 'priority' => 'medium', 'owner' => 'priya', 'lead' => 'design', 'age' => 4, 'due' => 21, 'modules' => ['Projects'], 'labels' => ['Improvement', 'Needs design'],
                'teams' => ['design' => ['status' => 'started', 'assignees' => ['priya'], 'estimate' => 2], 'engineering' => ['status' => 'backlog', 'estimate' => 5]],
                'depends' => ['engineering' => 'design'],
            ],
            ['ref' => 'shortcut-sheet', 'product' => 'TANDEM', 'project' => null, 'title' => 'Keyboard shortcut cheat sheet that follows the page', 'priority' => 'low', 'owner' => 'priya', 'lead' => 'design', 'age' => 28, 'completed' => 23, 'modules' => ['Projects'], 'labels' => ['Improvement'],
                'teams' => ['design' => ['status' => 'completed', 'assignees' => ['priya'], 'estimate' => 1], 'engineering' => ['status' => 'completed', 'assignees' => ['priya'], 'estimate' => 2, 'cycle' => 'previous']],
            ],
            ['ref' => 'issue-templates', 'product' => 'TANDEM', 'project' => null, 'title' => 'Issue templates per team', 'priority' => 'low', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 3, 'modules' => ['Projects'],
                'teams' => ['engineering' => ['status' => 'backlog', 'estimate' => 5]],
            ],
            ['ref' => 'automation-dryrun', 'product' => 'TANDEM', 'project' => null, 'title' => 'Dry-run an automation against an issue', 'priority' => 'low', 'owner' => 'jamie', 'lead' => 'engineering', 'age' => 25, 'completed' => 20, 'modules' => ['Automations'],
                'teams' => ['engineering' => ['status' => 'completed', 'assignees' => ['sam'], 'estimate' => 2, 'cycle' => 'previous']],
            ],
            ['ref' => 'automation-loop', 'product' => 'TANDEM', 'project' => null, 'title' => 'Guard automation loops across issues and conversations', 'priority' => 'high', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 17, 'completed' => 11, 'modules' => ['Automations'], 'labels' => ['Bug'],
                'teams' => ['engineering' => ['status' => 'completed', 'assignees' => ['sam'], 'estimate' => 3, 'cycle' => 'previous'], 'qa' => ['status' => 'completed', 'assignees' => ['lars'], 'estimate' => 1]],
            ],
            ['ref' => 'support-macros', 'product' => 'TANDEM', 'project' => null, 'title' => 'Macros for common support replies', 'priority' => 'medium', 'owner' => 'jamie', 'lead' => 'support', 'age' => 2, 'due' => 14, 'modules' => ['Mail'], 'labels' => ['Improvement'],
                'teams' => ['support' => ['status' => 'started', 'assignees' => ['jamie'], 'responsibility' => 'Collect the top 20 replies'], 'engineering' => ['status' => 'backlog', 'estimate' => 3]],
            ],
            ['ref' => 'overdue-digest', 'product' => 'TANDEM', 'project' => null, 'title' => 'Daily digest of overdue conversations', 'priority' => 'medium', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 21, 'due' => -4, 'modules' => ['Mail'],
                'body' => '<p>Overdue on purpose in the demo: the digest slipped a cycle.</p>',
                'teams' => ['engineering' => ['status' => 'started', 'assignees' => ['lars'], 'estimate' => 3, 'cycle' => 'current']],
            ],
            // --- org-level issues (NWL-n)
            ['ref' => 'office-move', 'product' => null, 'project' => null, 'title' => 'Office move checklist', 'priority' => 'medium', 'owner' => 'jamie', 'lead' => null, 'age' => 5, 'due' => 16, 'status' => 'started',
                'body' => '<ul data-type="taskList"><li data-type="taskItem" data-checked="true">Sign the lease</li><li data-type="taskItem" data-checked="false">Order desks</li><li data-type="taskItem" data-checked="false">Move the Wi-Fi</li></ul>',
                'teams' => [],
            ],
            ['ref' => 'offsite', 'product' => null, 'project' => null, 'title' => 'Plan the spring offsite', 'priority' => 'low', 'owner' => 'jamie', 'lead' => null, 'age' => 3, 'due' => 45, 'status' => 'backlog', 'teams' => []],
            ['ref' => 'security-review', 'product' => null, 'project' => null, 'title' => 'Annual security questionnaire for cobol.navy', 'priority' => 'high', 'owner' => 'sam', 'lead' => 'engineering', 'age' => 6, 'due' => 4, 'labels' => ['Customer-reported'],
                'teams' => ['engineering' => ['status' => 'started', 'assignees' => ['sam'], 'estimate' => 2, 'cycle' => 'current'], 'support' => ['status' => 'started', 'assignees' => ['jamie'], 'responsibility' => 'Customer-facing answers']],
            ],
        ];
    }

    protected function seedIssues(): void
    {
        $specs = $this->issueSpecs();

        foreach ($specs as $spec) {
            $this->createIssue($spec);
        }

        foreach ($specs as $spec) {
            $this->applyWorkflow($spec);
        }

        $this->issueActions->addRelation($this->issues['export-scheduler'], $this->issues['saved-reports'], IssueRelationType::Blocks, $this->users['sam']);
        $this->issueActions->addRelation($this->issues['rb-filters'], $this->issues['rb-grouping'], IssueRelationType::Related, $this->users['sam']);
        $this->issueActions->addRelation($this->issues['csv-last-row'], $this->issues['paginator-debt'], IssueRelationType::Related, $this->users['priya']);
        $this->graph->resolveFor($this->issues['saved-reports'], $this->issueActions);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    protected function createIssue(array $spec): void
    {
        $owner = $this->users[$spec['owner']];
        $actor = $owner;
        $product = $spec['product'] ? $this->products[$spec['product']] : null;
        $project = $spec['project'] ? $this->projects[$spec['project']] : null;

        $teams = [];

        foreach ($spec['teams'] as $teamKey => $entry) {
            $teams[] = [
                'team' => $this->teams[$teamKey],
                'responsibility' => $entry['responsibility'] ?? null,
                'is_required' => $entry['required'] ?? true,
                'estimate' => $entry['estimate'] ?? null,
                'assignees' => array_map(fn (string $user) => $this->users[$user]->id, $entry['assignees'] ?? []),
                'cycle' => isset($entry['cycle']) ? $this->cycles[$entry['cycle']] : null,
            ];
        }

        $issue = $this->issueActions->create($this->organization, [
            'title' => $spec['title'],
            'description_html' => $spec['body'] ?? null,
            'product' => $product,
            'project' => $project,
            'milestone' => isset($spec['milestone']) ? $this->milestones[$spec['milestone']] : null,
            'parent' => isset($spec['parent']) ? $this->issues[$spec['parent']] : null,
            'priority' => IssuePriority::fromKey($spec['priority']),
            'owner' => $owner,
            'lead_team' => $spec['lead'] ? $this->teams[$spec['lead']] : null,
            'estimate' => $spec['estimate'] ?? null,
            'due_date' => isset($spec['due']) ? $this->now->addDays($spec['due'])->toDateString() : null,
            'labels' => array_map(fn (string $name) => $this->labels[$name]->id, $spec['labels'] ?? []),
            'modules' => array_map(fn (string $name) => $this->modules[$name]->id, $spec['modules'] ?? []),
        ], $actor, $teams);

        $created = $this->now->subDays($spec['age'] ?? 0)->subHours(random_int(1, 8));
        $issue->forceFill(['created_at' => $created, 'updated_at' => $created, 'last_activity_at' => $created])->saveQuietly();
        $issue->workstreams()->update(['created_at' => $created, 'updated_at' => $created]);
        $issue->activities()->update(['created_at' => $created]);

        $this->issues[$spec['ref']] = $issue;
    }

    /**
     * Statuses, dependencies, overrides and comments: applied after every issue exists so
     * relations can point both ways.
     *
     * @param  array<string, mixed>  $spec
     */
    protected function applyWorkflow(array $spec): void
    {
        $issue = $this->issues[$spec['ref']]->fresh();
        $owner = $this->users[$spec['owner']];

        foreach ($spec['depends'] ?? [] as $teamKey => $onTeamKey) {
            $workstream = $this->workstream($issue, $teamKey);
            $on = $this->workstream($issue, $onTeamKey);
            $this->graph->addWorkstreamDependency($workstream, $on, $owner, $this->issueActions);
        }

        $completedAt = isset($spec['completed']) ? $this->now->subDays($spec['completed']) : null;
        $canceledAt = isset($spec['canceled']) ? $this->now->subDays($spec['canceled']) : null;

        // Dependencies first so the order of completion is honoured: dependencies before dependants.
        /** @var array<string, array<string, mixed>> $teamEntries */
        $teamEntries = $spec['teams'];
        $ordered = collect($teamEntries)->sortBy(fn (array $entry, string $key) => isset($spec['depends'][$key]) ? 1 : 0);

        foreach ($ordered as $teamKey => $entry) {
            $category = StatusCategory::from($entry['status']);
            $workstream = $this->workstream($issue, $teamKey);

            // Team workflows start at Todo; "backlog" and "unstarted" both leave the default status.
            if ($category === StatusCategory::Unstarted || $category === StatusCategory::Backlog) {
                continue;
            }

            $status = $this->workflows->defaultStatusFor($this->workflows->forTeam($this->teams[$teamKey]), $category);
            $actor = isset($entry['assignees'][0]) ? $this->users[$entry['assignees'][0]] : $owner;

            if ($category === StatusCategory::Completed || $category === StatusCategory::Canceled) {
                $started = $this->workflows->defaultStatusFor($this->workflows->forTeam($this->teams[$teamKey]), StatusCategory::Started);
                $this->issueActions->setWorkstreamStatus($workstream, $started, $actor);
            }

            $this->issueActions->setWorkstreamStatus($workstream->fresh(), $status, $actor);

            if ($category === StatusCategory::Completed && $completedAt) {
                $workstream->fresh()->forceFill(['completed_at' => $completedAt, 'started_at' => $completedAt->subDays(2)])->saveQuietly();
            }
        }

        $issue = $issue->fresh();

        $planned = collect($teamEntries)->contains(fn (array $entry) => $entry['status'] === 'unstarted');

        if ($teamEntries !== [] && $planned && $issue->status_category === StatusCategory::Backlog) {
            $workflow = $this->workflows->issueWorkflowFor($this->organization, $issue->product);
            $this->issueActions->setStatus($issue, $this->workflows->defaultStatusFor($workflow, StatusCategory::Unstarted), $owner);
            $issue = $issue->fresh();
        }

        if ($spec['teams'] === [] && isset($spec['status'])) {
            $workflow = $this->workflows->issueWorkflowFor($this->organization, $issue->product);
            $category = StatusCategory::from($spec['status']);

            if ($category !== $issue->status_category) {
                $this->issueActions->setStatus($issue, $this->workflows->defaultStatusFor($workflow, $category), $owner);
            }
        }

        if ($canceledAt && $issue->status_category !== StatusCategory::Canceled) {
            $workflow = $this->workflows->issueWorkflowFor($this->organization, $issue->product);
            $this->issueActions->setStatus($issue, $this->workflows->defaultStatusFor($workflow, StatusCategory::Canceled), $owner);
            $issue = $issue->fresh();
        }

        if (isset($spec['override'])) {
            $workflow = $this->workflows->issueWorkflowFor($this->organization, $issue->product);
            $this->issueActions->setStatus($issue->fresh(), $this->workflows->defaultStatusFor($workflow, StatusCategory::Completed), $owner, 'manual', true, $spec['override']);
        }

        foreach ($spec['comments'] ?? [] as [$author, $html]) {
            $mentions = [];
            $html = preg_replace_callback('/\{(\w+)\}/', function (array $m) use (&$mentions) {
                $mentions[] = $this->users[$m[1]]->id;

                return (string) $this->users[$m[1]]->id;
            }, $html) ?? $html;

            $this->comments->post($issue->fresh(), $this->users[$author], $html, $mentions);
        }

        $issue = $issue->fresh();

        if ($completedAt && $issue->completed_at) {
            $issue->forceFill(['completed_at' => $completedAt, 'updated_at' => $completedAt, 'last_activity_at' => $completedAt])->saveQuietly();
        }

        if ($canceledAt && $issue->canceled_at) {
            $issue->forceFill(['canceled_at' => $canceledAt, 'updated_at' => $canceledAt, 'last_activity_at' => $canceledAt])->saveQuietly();
        }

        $this->issues[$spec['ref']] = $issue;
    }

    protected function workstream(Issue $issue, string $teamKey): IssueWorkstream
    {
        return $issue->workstreams()->where('team_id', $this->teams[$teamKey]->id)->firstOrFail();
    }

    protected function seedEmailLinks(): void
    {
        $priya = $this->users['priya'];
        $sam = $this->users['sam'];

        $csv = $this->conversation('Export to CSV silently drops the last row');
        $sso = $this->conversation('Can we add SSO for our whole fleet?');
        $dst = $this->conversation('Wrong timezone on scheduled sends');
        $invoice = $this->conversation('Invoice attached twice?');

        if ($csv) {
            $this->links->link($this->issues['csv-last-row'], $csv, $priya, 'Ada reported this on the Support inbox.', IssueLinkKind::CreatedFrom);

            if ($message = $csv->messages()->where('direction', 'inbound')->orderBy('sent_at')->first()) {
                $this->links->shareExcerpt($this->issues['csv-last-row'], $message, $priya);
            }
        }

        if ($sso) {
            $this->links->link($this->issues['saml-sso'], $sso, $sam, 'Design partner offer from Grace.', IssueLinkKind::CreatedFrom);
        }

        if ($dst) {
            $this->links->link($this->issues['gmail-threading'], $dst, $priya, null, IssueLinkKind::Linked);
        }

        if ($invoice) {
            $this->links->link($this->issues['invoice-layout'], $invoice, $sam, null, IssueLinkKind::Linked);
        }
    }

    protected function conversation(string $subject): ?Conversation
    {
        return Conversation::query()->where('organization_id', $this->organization->id)->where('subject', $subject)->orderBy('id')->first();
    }

    protected function seedViewsAndFavorites(): void
    {
        $jamie = $this->users['jamie'];

        IssueView::query()->create([
            'organization_id' => $this->organization->id,
            'scope' => IssueViewScope::Personal,
            'user_id' => $jamie->id,
            'created_by_id' => $jamie->id,
            'name' => 'Urgent and due soon',
            'icon' => 'flame',
            'color' => 'rose',
            'filters' => ['match' => 'all', 'groups' => [['match' => 'any', 'clauses' => [['field' => 'priority', 'operator' => 'in', 'value' => ['urgent', 'high']], ['field' => 'due_date', 'operator' => 'before', 'value' => $this->now->addDays(7)->toDateString()]]], ['match' => 'all', 'clauses' => [['field' => 'status_category', 'operator' => 'not_in', 'value' => ['completed', 'canceled']]]]]],
            'display' => ['mode' => 'list', 'group_by' => 'priority', 'sort' => 'due_date', 'dir' => 'asc'],
            'position' => 1,
        ]);

        IssueView::query()->create([
            'organization_id' => $this->organization->id,
            'scope' => IssueViewScope::Team,
            'team_id' => $this->teams['engineering']->id,
            'created_by_id' => $this->users['sam']->id,
            'name' => 'Engineering, this cycle',
            'icon' => 'repeat',
            'color' => 'violet',
            'filters' => ['match' => 'all', 'groups' => [['match' => 'all', 'clauses' => [['field' => 'team', 'operator' => 'in', 'value' => [$this->teams['engineering']->id]], ['field' => 'cycle', 'operator' => 'equals', 'value' => 'active']]]]],
            'display' => ['mode' => 'board', 'group_by' => 'status'],
            'position' => 1,
        ]);

        IssueView::query()->create([
            'organization_id' => $this->organization->id,
            'scope' => IssueViewScope::Organization,
            'created_by_id' => $jamie->id,
            'name' => 'Customer-reported bugs',
            'icon' => 'bug',
            'color' => 'teal',
            'filters' => ['match' => 'all', 'groups' => [['match' => 'all', 'clauses' => [['field' => 'label', 'operator' => 'in', 'value' => [$this->labels['Bug']->id, $this->labels['Customer-reported']->id]]]]]],
            'display' => ['mode' => 'list', 'group_by' => 'product', 'sort' => 'created_at', 'dir' => 'desc'],
            'position' => 2,
        ]);

        $this->favorites->toggle($jamie, $this->organization, $this->products['FRONT']);
        $this->favorites->toggle($jamie, $this->organization, $this->projects['reporting']);
        $this->favorites->toggle($this->users['sam'], $this->organization, $this->projects['gmail']);
    }

    protected function seedAutomations(): void
    {
        $sam = $this->users['sam'];
        $engineering = $this->teams['engineering'];
        $qa = $this->teams['qa'];
        $position = (int) $this->organization->automationRules()->max('position');

        $this->organization->automationRules()->create([
            'subject_type' => 'issue',
            'product_id' => $this->products['FRONT']->id,
            'created_by_id' => $sam->id,
            'name' => 'QA follows Engineering',
            'description' => 'When Engineering finishes its part of a Frontier issue, bring QA in.',
            'trigger' => AutomationTrigger::WorkstreamStatusChanged,
            'match' => 'all',
            'conditions' => [
                ['field' => 'workstream_team', 'operator' => 'equals', 'value' => (string) $engineering->id],
                ['field' => 'workstream_status_category', 'operator' => 'equals', 'value' => 'completed'],
            ],
            'actions' => [
                ['type' => 'add_team_to_issue', 'params' => ['team_id' => $qa->id, 'is_required' => true]],
                ['type' => 'add_issue_comment', 'params' => ['body' => 'Engineering is done on {{ issue.identifier }}; QA has been added for sign-off.']],
            ],
            'is_active' => true,
            'position' => ++$position,
        ]);

        $this->organization->automationRules()->create([
            'subject_type' => 'issue',
            'created_by_id' => $sam->id,
            'name' => 'Urgent bugs get an owner',
            'description' => 'Urgent issues labelled Bug are owned by Sam and the lead is notified.',
            'trigger' => AutomationTrigger::IssueCreated,
            'match' => 'all',
            'conditions' => [
                ['field' => 'issue_priority', 'operator' => 'equals', 'value' => 'urgent'],
                ['field' => 'issue_label', 'operator' => 'in', 'value' => [(string) $this->labels['Bug']->id]],
            ],
            'actions' => [
                ['type' => 'set_issue_owner', 'params' => ['user_id' => $sam->id]],
                ['type' => 'notify_users', 'params' => ['user_ids' => [$sam->id, $this->users['jamie']->id], 'message' => 'An urgent bug just came in.']],
            ],
            'is_active' => true,
            'position' => ++$position,
        ]);

        $this->organization->automationRules()->create([
            'subject_type' => 'issue',
            'created_by_id' => $sam->id,
            'name' => 'Nudge overdue issues',
            'trigger' => AutomationTrigger::IssueOverdue,
            'match' => 'all',
            'conditions' => [['field' => 'issue_has_blockers', 'operator' => 'is_false', 'value' => null]],
            'actions' => [['type' => 'add_issue_comment', 'params' => ['body' => 'This issue is past its due date and nothing is blocking it. Reschedule or close it.']]],
            'is_active' => true,
            'position' => ++$position,
            'cooldown_minutes' => 1440,
        ]);

        $support = $this->organization->inboxes()->where('name', 'Support')->first();

        $this->organization->automationRules()->create([
            'inbox_id' => $support?->id,
            'created_by_id' => $this->users['jamie']->id,
            'name' => 'Bug reports become Frontier issues',
            'description' => 'Support emails that mention a bug open an Engineering issue and link the thread.',
            'trigger' => AutomationTrigger::ConversationReceived,
            'match' => 'any',
            'conditions' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'bug'],
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'broken'],
            ],
            'actions' => [
                ['type' => 'create_issue', 'params' => ['product_id' => $this->products['FRONT']->id, 'team_ids' => [$engineering->id], 'priority' => 'high', 'label_ids' => [$this->labels['Bug']->id, $this->labels['Customer-reported']->id], 'title_template' => '{{ conversation.subject }}', 'include_excerpt' => false]],
            ],
            'is_active' => true,
            'position' => ++$position,
        ]);
    }
}
