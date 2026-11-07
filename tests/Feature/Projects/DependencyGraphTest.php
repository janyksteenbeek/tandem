<?php

use App\Enums\Projects\IssueRelationType;
use App\Enums\Projects\StatusCategory;
use App\Exceptions\Projects\DependencyCycleException;
use App\Models\Projects\Product;
use App\Notifications\Projects\BlockerResolvedNotification;
use App\Notifications\Projects\WorkstreamHandoffNotification;
use App\Services\Projects\DependencyGraph;
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
    $this->design = $w['design'];
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $this->actions = app(IssueActions::class);
    $this->graph = app(DependencyGraph::class);
    $this->workflows = app(WorkflowActions::class);
});

function statusOf($team, StatusCategory $category)
{
    return app(WorkflowActions::class)->forTeam($team)->statusFor($category);
}

test('blocking relations count blockers and resolve when the blocker completes', function () {
    $blocker = $this->actions->create($this->organization, ['title' => 'Blocker', 'product' => $this->product], $this->admin);
    $blocked = $this->actions->create($this->organization, ['title' => 'Blocked', 'product' => $this->product, 'owner' => $this->member], $this->admin);

    $this->actions->addRelation($blocker, $blocked, IssueRelationType::Blocks, $this->admin);

    expect($blocked->fresh()->blocked_by_count)->toBe(1);

    $done = $this->workflows->issueWorkflowFor($this->organization, $this->product)->statusFor(StatusCategory::Completed);
    $this->actions->setStatus($blocker, $done, $this->admin);

    expect($blocked->fresh()->blocked_by_count)->toBe(0);
    $this->assertDatabaseHas('issue_activities', ['issue_id' => $blocked->id, 'type' => 'dependency_resolved']);
    Notification::assertSentTo($this->member, BlockerResolvedNotification::class);

    $todo = $this->workflows->issueWorkflowFor($this->organization, $this->product)->statusFor(StatusCategory::Unstarted);
    $this->actions->setStatus($blocker->fresh(), $todo, $this->admin);

    expect($blocked->fresh()->blocked_by_count)->toBe(1);
});

test('direct and transitive dependency cycles are rejected with the path', function () {
    $a = $this->actions->create($this->organization, ['title' => 'A', 'product' => $this->product], $this->admin);
    $b = $this->actions->create($this->organization, ['title' => 'B', 'product' => $this->product], $this->admin);
    $c = $this->actions->create($this->organization, ['title' => 'C', 'product' => $this->product], $this->admin);

    $this->actions->addRelation($a, $b, IssueRelationType::Blocks, $this->admin);
    $this->actions->addRelation($b, $c, IssueRelationType::Blocks, $this->admin);

    try {
        $this->actions->addRelation($c, $a, IssueRelationType::Blocks, $this->admin);
        $this->fail('Expected a cycle exception');
    } catch (DependencyCycleException $e) {
        expect($e->path)->toContain('FRONT-1')->toContain('FRONT-3');
    }

    expect(fn () => $this->actions->addRelation($b, $a, IssueRelationType::Blocks, $this->admin))->toThrow(DependencyCycleException::class);
});

test('workstream dependencies hand work from one team to the next', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Report redesign', 'product' => $this->product], $this->admin, [
        ['team' => $this->design, 'assignees' => [$this->other->id]],
        ['team' => $this->engineering, 'assignees' => [$this->member->id]],
    ]);

    $design = $issue->workstreams()->where('team_id', $this->design->id)->first();
    $engineering = $issue->workstreams()->where('team_id', $this->engineering->id)->first();

    $this->graph->addWorkstreamDependency($engineering, $design, $this->admin, $this->actions);

    expect($engineering->fresh()->blocked_by_count)->toBe(1)
        ->and($issue->fresh()->blocked_workstreams_count)->toBe(1);

    expect(fn () => $this->actions->setWorkstreamStatus($engineering->fresh(), statusOf($this->engineering, StatusCategory::Completed), $this->member))
        ->toThrow(ValidationException::class);

    $this->actions->setWorkstreamStatus($design, statusOf($this->design, StatusCategory::Completed), $this->other);

    expect($engineering->fresh()->blocked_by_count)->toBe(0)
        ->and($issue->fresh()->blocked_workstreams_count)->toBe(0);

    $this->assertDatabaseHas('issue_activities', ['issue_id' => $issue->id, 'type' => 'handoff']);
    Notification::assertSentTo($this->member, WorkstreamHandoffNotification::class);
});

test('a cycle through required workstreams across two issues is caught', function () {
    $one = $this->actions->create($this->organization, ['title' => 'One', 'product' => $this->product], $this->admin, [['team' => $this->design]]);
    $two = $this->actions->create($this->organization, ['title' => 'Two', 'product' => $this->product], $this->admin, [['team' => $this->engineering]]);

    $designOfOne = $one->workstreams()->first();
    $engineeringOfTwo = $two->workstreams()->first();

    // One's design work waits for issue Two…
    $this->graph->addWorkstreamDependency($designOfOne, $two, $this->admin, $this->actions);

    // …so Two's engineering work may not wait for issue One (One waits for its required design workstream).
    $this->graph->addWorkstreamDependency($engineeringOfTwo, $one, $this->admin, $this->actions);
})->throws(DependencyCycleException::class);

test('removing a team cleans up dependencies that pointed at it', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Cleanup', 'product' => $this->product], $this->admin, [['team' => $this->design], ['team' => $this->engineering]]);
    $design = $issue->workstreams()->where('team_id', $this->design->id)->first();
    $engineering = $issue->workstreams()->where('team_id', $this->engineering->id)->first();

    $this->graph->addWorkstreamDependency($engineering, $design, $this->admin, $this->actions);
    $this->actions->removeTeam($issue, $this->design, $this->admin);

    expect($engineering->fresh()->blocked_by_count)->toBe(0);
    $this->assertDatabaseMissing('workstream_dependencies', ['workstream_id' => $engineering->id]);
});
