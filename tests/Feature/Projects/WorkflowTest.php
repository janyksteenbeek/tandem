<?php

use App\Enums\Projects\StatusCategory;
use App\Models\Projects\Product;
use App\Services\Projects\IssueActions;
use App\Services\Projects\WorkflowActions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProjectsTestHelpers;

beforeEach(function () {
    Notification::fake();

    $w = ProjectsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->other = $w['other'];
    $this->engineering = $w['engineering'];
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $this->workflows = app(WorkflowActions::class);
    $this->actions = app(IssueActions::class);
    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, ...$parameters]);
});

test('workflows are created lazily with sensible defaults', function () {
    $org = $this->workflows->defaultIssueWorkflow($this->organization);
    $team = $this->workflows->forTeam($this->engineering);

    expect($org->statuses->pluck('name')->all())->toBe(['Backlog', 'Todo', 'In Progress', 'Done', 'Canceled'])
        ->and($org->defaultStatus()->category)->toBe(StatusCategory::Backlog)
        ->and($team->statuses->pluck('name')->all())->toBe(['Todo', 'In Progress', 'In Review', 'Done', 'Canceled'])
        ->and($team->defaultStatus()->category)->toBe(StatusCategory::Unstarted)
        ->and($this->workflows->forTeam($this->engineering)->id)->toBe($team->id);
});

test('team leads can add, rename, reorder and delete statuses with remapping', function () {
    $workflow = $this->workflows->forTeam($this->engineering);
    $issue = $this->actions->create($this->organization, ['title' => 'Remap me', 'product' => $this->product], $this->admin, [['team' => $this->engineering]]);
    $stream = $issue->workstreams()->first();

    $response = $this->actingAs($this->member)->postJson(($this->url)('projects.workflows.statuses.store', ['workflow' => $workflow->uuid]), [
        'name' => 'Testing', 'category' => 'started', 'color' => 'teal',
    ])->assertCreated();

    $testing = collect($response->json('workflow.statuses'))->firstWhere('name', 'Testing');
    expect($testing['category'])->toBe('started');

    $this->actions->setWorkstreamStatus($stream, $workflow->statuses()->whereKey($testing['id'])->first(), $this->member);
    expect($stream->fresh()->status_id)->toBe($testing['id']);

    $inProgress = $workflow->statuses()->where('name', 'In Progress')->first();

    $this->actingAs($this->member)
        ->deleteJson(($this->url)('projects.workflows.statuses.destroy', ['workflow' => $workflow->uuid, 'status' => $workflow->statuses()->whereKey($testing['id'])->first()->uuid]), ['replacement_status_id' => $inProgress->id])
        ->assertOk()
        ->assertJsonPath('remapped', 1);

    expect($stream->fresh()->status_id)->toBe($inProgress->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'workflow.status_deleted']);
});

test('a workflow must keep one status per required category and one default', function () {
    $workflow = $this->workflows->forTeam($this->engineering);
    $done = $workflow->statuses()->where('category', 'completed')->first();
    $todo = $workflow->statuses()->where('category', 'unstarted')->first();

    expect(fn () => $this->workflows->deleteStatus($done, $todo, $this->admin))->toThrow(ValidationException::class);

    $this->workflows->deleteStatus($todo, $workflow->statuses()->where('name', 'In Progress')->first(), $this->admin);
})->throws(ValidationException::class);

test('transitions restrict moves only when configured', function () {
    $workflow = $this->workflows->forTeam($this->engineering);
    $todo = $workflow->statusFor(StatusCategory::Unstarted);
    $inProgress = $workflow->statuses()->where('name', 'In Progress')->first();
    $done = $workflow->statusFor(StatusCategory::Completed);

    $issue = $this->actions->create($this->organization, ['title' => 'Gated', 'product' => $this->product], $this->admin, [['team' => $this->engineering]]);
    $stream = $issue->workstreams()->first();

    $this->workflows->setTransitions($workflow, [['from' => $todo->id, 'to' => $inProgress->id], ['from' => $inProgress->id, 'to' => $done->id]], $this->admin);

    expect(fn () => $this->actions->setWorkstreamStatus($stream, $done, $this->member))->toThrow(ValidationException::class);

    $this->actions->setWorkstreamStatus($stream, $inProgress, $this->member);
    $this->actions->setWorkstreamStatus($stream->fresh(), $done, $this->member);

    expect($stream->fresh()->status_category)->toBe(StatusCategory::Completed);
});

test('only leads and admins manage a team workflow', function () {
    $workflow = $this->workflows->forTeam($this->engineering);

    $this->actingAs($this->other)->postJson(($this->url)('projects.workflows.statuses.store', ['workflow' => $workflow->uuid]), ['name' => 'X', 'category' => 'started'])->assertForbidden();
    $this->actingAs($this->admin)->postJson(($this->url)('projects.workflows.statuses.store', ['workflow' => $workflow->uuid]), ['name' => 'X', 'category' => 'started'])->assertCreated();
});

test('a product can get its own issue workflow copied from the default and issues move between them by category', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Before', 'product' => $this->product], $this->admin);
    $started = $this->workflows->defaultIssueWorkflow($this->organization)->statusFor(StatusCategory::Started);
    $this->actions->setStatus($issue, $started, $this->admin);

    $own = $this->workflows->createProductWorkflow($this->product, $this->admin);
    $this->product->refresh();

    expect($own->statuses)->toHaveCount(5)->and($this->product->issue_workflow_id)->toBe($own->id);

    $other = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'OTHER']);
    $this->actions->move($issue, $other, null, $this->admin);

    // Back on the org default workflow: same category, that workflow's status.
    expect($issue->fresh()->status->workflow_id)->toBe($this->workflows->defaultIssueWorkflow($this->organization)->id)
        ->and($issue->fresh()->status_category)->toBe(StatusCategory::Started);
});
