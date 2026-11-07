<?php

use App\Enums\Projects\StatusCategory;
use App\Exceptions\Projects\CompletionBlockedException;
use App\Models\Projects\Product;
use App\Notifications\Projects\IssueCompletionConflictNotification;
use App\Notifications\Projects\IssueReadyToCompleteNotification;
use App\Services\Projects\IssueActions;
use App\Services\Projects\WorkflowActions;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ProjectsTestHelpers;

beforeEach(function () {
    Notification::fake();

    $w = ProjectsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->engineering = $w['engineering'];
    $this->design = $w['design'];
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $this->actions = app(IssueActions::class);
    $this->workflows = app(WorkflowActions::class);

    $this->issue = $this->actions->create($this->organization, ['title' => 'Redesign reporting', 'product' => $this->product, 'owner' => $this->admin], $this->admin, [
        ['team' => $this->design],
        ['team' => $this->engineering],
    ]);
});

function teamStatus($team, StatusCategory $category)
{
    return app(WorkflowActions::class)->forTeam($team)->statusFor($category);
}

test('each team gets its own workstream with the team default status', function () {
    $workstreams = $this->issue->workstreams()->with('status')->get();

    expect($workstreams)->toHaveCount(2)
        ->and($workstreams->pluck('team_id')->all())->toEqualCanonicalizing([$this->design->id, $this->engineering->id])
        ->and($workstreams->every(fn ($w) => $w->status->is_default))->toBeTrue()
        ->and($this->issue->fresh()->workstreams_required_count)->toBe(2);
});

test('starting one workstream starts the issue but leaves the other team untouched', function () {
    $design = $this->issue->workstreams()->where('team_id', $this->design->id)->first();

    $this->actions->setWorkstreamStatus($design, teamStatus($this->design, StatusCategory::Started), $this->member);

    $issue = $this->issue->fresh();
    $engineering = $issue->workstreams()->where('team_id', $this->engineering->id)->first();

    expect($issue->status_category)->toBe(StatusCategory::Started)
        ->and($engineering->status_category)->toBe(StatusCategory::Unstarted);
});

test('completing one team does not complete the issue while a required team is open', function () {
    $design = $this->issue->workstreams()->where('team_id', $this->design->id)->first();
    $this->actions->setWorkstreamStatus($design, teamStatus($this->design, StatusCategory::Completed), $this->member);

    $issue = $this->issue->fresh();

    expect($issue->status_category)->toBe(StatusCategory::Started)
        ->and($issue->is_ready_to_complete)->toBeFalse()
        ->and($issue->workstreams_completed_count)->toBe(1);
});

test('the issue completes automatically once every required workstream is finished', function () {
    foreach ([$this->design, $this->engineering] as $team) {
        $workstream = $this->issue->workstreams()->where('team_id', $team->id)->first();
        $this->actions->setWorkstreamStatus($workstream, teamStatus($team, StatusCategory::Completed), $this->member);
    }

    $issue = $this->issue->fresh();

    expect($issue->status_category)->toBe(StatusCategory::Completed)
        ->and($issue->completed_at)->not->toBeNull()
        ->and($issue->activities()->where('type', 'status_changed')->get()->last()->payload['source'])->toBe('auto');
});

test('optional workstreams do not block completion', function () {
    $engineering = $this->issue->workstreams()->where('team_id', $this->engineering->id)->first();
    $this->actions->updateWorkstream($engineering, ['is_required' => false], $this->admin);

    $design = $this->issue->workstreams()->where('team_id', $this->design->id)->first();
    $this->actions->setWorkstreamStatus($design, teamStatus($this->design, StatusCategory::Completed), $this->member);

    expect($this->issue->fresh()->status_category)->toBe(StatusCategory::Completed);
});

test('manually completing an issue with open required workstreams is refused unless overridden', function () {
    $done = $this->workflows->issueWorkflowFor($this->organization, $this->product)->statusFor(StatusCategory::Completed);

    expect(fn () => $this->actions->setStatus($this->issue, $done, $this->admin))->toThrow(CompletionBlockedException::class);

    $this->actions->setStatus($this->issue, $done, $this->admin, 'manual', true, 'Shipping anyway');

    $issue = $this->issue->fresh();

    expect($issue->status_category)->toBe(StatusCategory::Completed)
        ->and($issue->status_overridden_at)->not->toBeNull()
        ->and($issue->activities()->where('type', 'status_overridden')->exists())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', ['action' => 'issue.completion_overridden', 'organization_id' => $this->organization->id]);
});

test('an automation may never complete an issue that is not ready', function () {
    $done = $this->workflows->issueWorkflowFor($this->organization, $this->product)->statusFor(StatusCategory::Completed);

    $this->actions->setStatus($this->issue, $done, null, 'automation', true);
})->throws(CompletionBlockedException::class);

test('reopening a team workstream on a completed issue flags a conflict instead of reopening the issue', function () {
    foreach ([$this->design, $this->engineering] as $team) {
        $workstream = $this->issue->workstreams()->where('team_id', $team->id)->first();
        $this->actions->setWorkstreamStatus($workstream, teamStatus($team, StatusCategory::Completed), $this->member);
    }

    $design = $this->issue->workstreams()->where('team_id', $this->design->id)->first();
    $this->actions->setWorkstreamStatus($design, teamStatus($this->design, StatusCategory::Started), $this->member);

    $issue = $this->issue->fresh();

    expect($issue->status_category)->toBe(StatusCategory::Completed)
        ->and($issue->is_ready_to_complete)->toBeFalse()
        ->and($issue->activities()->where('type', 'completion_conflict')->exists())->toBeTrue();

    Notification::assertSentTo($this->admin, IssueCompletionConflictNotification::class);
});

test('when auto completion is off the owner is told the issue is ready', function () {
    $workflow = $this->workflows->issueWorkflowFor($this->organization, $this->product);
    $this->workflows->updateWorkflow($workflow, ['auto_complete' => false], $this->admin);

    foreach ([$this->design, $this->engineering] as $team) {
        $workstream = $this->issue->workstreams()->where('team_id', $team->id)->first();
        $this->actions->setWorkstreamStatus($workstream, teamStatus($team, StatusCategory::Completed), $this->member);
    }

    $issue = $this->issue->fresh();

    expect($issue->status_category)->toBe(StatusCategory::Started)
        ->and($issue->is_ready_to_complete)->toBeTrue();

    Notification::assertSentTo($this->admin, IssueReadyToCompleteNotification::class);
});

test('removing a team recomputes completion', function () {
    $design = $this->issue->workstreams()->where('team_id', $this->design->id)->first();
    $this->actions->setWorkstreamStatus($design, teamStatus($this->design, StatusCategory::Completed), $this->member);
    $this->actions->removeTeam($this->issue, $this->engineering, $this->admin);

    expect($this->issue->fresh()->status_category)->toBe(StatusCategory::Completed);
});

test('a simple issue without teams can be completed directly', function () {
    $simple = $this->actions->create($this->organization, ['title' => 'Quick fix', 'product' => $this->product], $this->admin);
    $done = $this->workflows->issueWorkflowFor($this->organization, $this->product)->statusFor(StatusCategory::Completed);

    $this->actions->setStatus($simple, $done, $this->admin);

    expect($simple->fresh()->status_category)->toBe(StatusCategory::Completed);
});
