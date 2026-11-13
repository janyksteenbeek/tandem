<?php

use App\Enums\Projects\StatusCategory;
use App\Models\Projects\Issue;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Services\Projects\IssueActions;
use App\Services\Projects\WorkflowActions;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectsTestHelpers;

beforeEach(function () {
    Notification::fake();
    $this->withoutVite();

    $w = ProjectsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->other = $w['other'];
    $this->outsider = $w['outsider'];
    $this->engineering = $w['engineering'];
    $this->design = $w['design'];
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $this->actions = app(IssueActions::class);
    $this->workflows = app(WorkflowActions::class);
    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, ...$parameters]);
});

test('the issue list renders with filters, display options and lookups', function () {
    $this->actions->create($this->organization, ['title' => 'Listed', 'product' => $this->product], $this->admin);

    $this->actingAs($this->admin)
        ->get(($this->url)('projects.issues.index', ['mode' => 'list', 'group_by' => 'priority']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/issues/Index')
            ->where('display.group_by', 'priority')
            ->where('container.type', 'organization')
            ->has('issues.data', 1, fn (Assert $issue) => $issue->where('identifier', 'FRONT-1')->etc())
            ->has('lookups.members')
            ->has('workflow'));
});

test('creating an issue through the API assigns teams and links nothing else', function () {
    $response = $this->actingAs($this->admin)->postJson(($this->url)('projects.issues.store'), [
        'title' => 'Build the thing',
        'description_html' => '<p>Details <script>alert(1)</script></p>',
        'product_id' => $this->product->id,
        'priority' => 'high',
        'owner_id' => $this->member->id,
        'teams' => [
            ['team_id' => $this->engineering->id, 'responsibility' => 'Implement', 'assignee_ids' => [$this->member->id]],
            ['team_id' => $this->design->id, 'is_required' => false],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('issue.identifier', 'FRONT-1')
        ->assertJsonPath('issue.priority', 'high')
        ->assertJsonPath('issue.owner.id', $this->member->id)
        ->assertJsonCount(2, 'issue.workstreams')
        ->assertJsonPath('issue.workstreams.0.assignees.0.id', $this->member->id)
        ->assertJsonPath('issue.workstreams.1.is_required', false);

    expect($response->json('issue.description_html'))->not->toContain('<script>');
});

test('members outside the organization get nothing', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Private org', 'product' => $this->product], $this->admin);

    $this->actingAs($this->outsider)->get(($this->url)('projects.issues.show', ['issue' => $issue->identifier]))->assertForbidden();
    $this->actingAs($this->outsider)->postJson(($this->url)('projects.issues.store'), ['title' => 'nope'])->assertForbidden();
});

test('an issue is addressed by identifier within its organization', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Addressable', 'product' => $this->product], $this->admin);

    $this->actingAs($this->member)
        ->get(($this->url)('projects.issues.show', ['issue' => 'front-1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('projects/issues/Show')->where('issue.identifier', 'FRONT-1')->where('issue.uuid', $issue->uuid));

    $this->actingAs($this->member)->get(($this->url)('projects.issues.show', ['issue' => 'FRONT-999']))->assertNotFound();
});

test('completing an issue with open required workstreams returns the readiness so the UI can offer an override', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Blocked done', 'product' => $this->product], $this->admin, [['team' => $this->engineering]]);
    $done = $this->workflows->issueWorkflowFor($this->organization, $this->product)->statusFor(StatusCategory::Completed);

    $this->actingAs($this->admin)
        ->postJson(($this->url)('projects.issues.status', ['issue' => $issue->identifier]), ['status_id' => $done->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'workstreams_incomplete')
        ->assertJsonPath('readiness.required', 1);

    $this->actingAs($this->admin)
        ->postJson(($this->url)('projects.issues.status', ['issue' => $issue->identifier]), ['status_id' => $done->id, 'override' => true, 'reason' => 'ship it'])
        ->assertOk()
        ->assertJsonPath('issue.status_category', 'completed');
});

test('workstream participants may change their status but not the overall status', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Team work', 'product' => $this->product], $this->admin, [['team' => $this->design]]);
    $stream = $issue->workstreams()->first();
    $started = $this->workflows->forTeam($this->design)->statusFor(StatusCategory::Started);
    $overall = $this->workflows->issueWorkflowFor($this->organization, $this->product)->statusFor(StatusCategory::Started);

    $this->actingAs($this->other)
        ->postJson(($this->url)('projects.issues.workstreams.status', ['issue' => $issue->identifier, 'workstream' => $stream->uuid]), ['status_id' => $started->id])
        ->assertOk()
        ->assertJsonPath('issue.workstreams.0.status.category', 'started')
        ->assertJsonPath('issue.status_category', 'started');

    $this->actingAs($this->other)
        ->postJson(($this->url)('projects.issues.status', ['issue' => $issue->identifier]), ['status_id' => $overall->id])
        ->assertForbidden();
});

test('an issue can be moved into a project and keeps its identifier', function () {
    $project = Project::factory()->forProduct($this->product)->create();
    $issue = $this->actions->create($this->organization, ['title' => 'Moving', 'product' => $this->product], $this->admin);

    $this->actingAs($this->admin)
        ->postJson(($this->url)('projects.issues.move', ['issue' => $issue->identifier]), ['project_id' => $project->id])
        ->assertOk()
        ->assertJsonPath('issue.project.id', $project->id)
        ->assertJsonPath('issue.identifier', 'FRONT-1');

    $this->assertDatabaseHas('issue_activities', ['issue_id' => $issue->id, 'type' => 'moved']);
});

test('bulk actions skip issues the user may not change', function () {
    $mine = $this->actions->create($this->organization, ['title' => 'Mine', 'product' => $this->product, 'owner' => $this->other], $this->admin);
    $theirs = $this->actions->create($this->organization, ['title' => 'Theirs', 'product' => $this->product, 'owner' => $this->admin], $this->admin);

    $response = $this->actingAs($this->other)->postJson(($this->url)('projects.issues.bulk'), [
        'issues' => [$mine->uuid, $theirs->uuid],
        'action' => 'priority',
        'priority' => 'urgent',
    ])->assertOk();

    expect($response->json('issues'))->toHaveCount(1)
        ->and($mine->fresh()->priority->key())->toBe('urgent')
        ->and($theirs->fresh()->priority->key())->toBe('none');
});

test('labels can be created on the fly and toggled', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Labelled', 'product' => $this->product], $this->admin);

    $this->actingAs($this->admin)
        ->postJson(($this->url)('projects.issues.labels', ['issue' => $issue->identifier]), ['name' => 'Bug', 'attached' => true])
        ->assertOk()
        ->assertJsonPath('issue.labels.0.name', 'Bug');

    $label = $issue->fresh()->labels()->first();

    $this->actingAs($this->admin)
        ->postJson(($this->url)('projects.issues.labels', ['issue' => $issue->identifier]), ['label_id' => $label->id, 'attached' => false])
        ->assertOk()
        ->assertJsonCount(0, 'issue.labels');
});

test('search finds issues by identifier and title', function () {
    $this->actions->create($this->organization, ['title' => 'Portfolio report returns are wrong', 'product' => $this->product], $this->admin);
    $this->actions->create($this->organization, ['title' => 'Unrelated', 'product' => $this->product], $this->admin);

    $this->actingAs($this->admin)->getJson(($this->url)('projects.issues.search', ['q' => 'FRONT-2']))->assertOk()->assertJsonCount(1, 'issues')->assertJsonPath('issues.0.identifier', 'FRONT-2');
    $this->actingAs($this->admin)->getJson(($this->url)('projects.issues.search', ['q' => 'portfolio']))->assertOk()->assertJsonCount(1, 'issues')->assertJsonPath('issues.0.identifier', 'FRONT-1');
});

test('my issues shows assignments without duplicate rows and counts buckets', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Two teams, one me', 'product' => $this->product, 'owner' => $this->member], $this->admin, [
        ['team' => $this->engineering, 'assignees' => [$this->member->id]],
        ['team' => $this->design, 'assignees' => [$this->member->id]],
    ]);

    $this->actingAs($this->member)
        ->get(($this->url)('projects.my-issues', ['bucket' => 'assigned']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/my-issues/Index')
            ->where('bucket', 'assigned')
            ->has('issues.data', 1, fn (Assert $row) => $row->where('uuid', $issue->uuid)->has('assignees', 1)->etc()));
});

test('deleting and restoring an issue is audited', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Gone', 'product' => $this->product], $this->admin);

    $this->actingAs($this->admin)->deleteJson(($this->url)('projects.issues.destroy', ['issue' => $issue->identifier]))->assertOk();
    expect(Issue::query()->find($issue->id))->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'issue.deleted']);

    $this->actingAs($this->admin)->postJson(($this->url)('projects.issues.restore', ['identifier' => $issue->identifier]))->assertOk()->assertJsonPath('issue.identifier', 'FRONT-1');
});
