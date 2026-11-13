<?php

use App\Enums\OrganizationRole;
use App\Enums\Projects\ProjectAccessLevel;
use App\Enums\Projects\StatusCategory;
use App\Models\Projects\Issue;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;
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
    $this->member = $w['member'];       // Engineering lead
    $this->other = $w['other'];         // Design member
    $this->outsider = $w['outsider'];
    $this->engineering = $w['engineering'];
    $this->design = $w['design'];
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $this->actions = app(IssueActions::class);
    $this->access = app(IssueAccess::class);

    $this->stranger = User::factory()->create();
    $this->organization->memberships()->create(['user_id' => $this->stranger->id, 'role' => OrganizationRole::Member]);
    $this->stranger->forceFill(['current_organization_id' => $this->organization->id])->save();
});

test('organization members can see issues outside projects, outsiders cannot', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Open to all', 'product' => $this->product], $this->admin);

    expect($this->access->levelFor($this->stranger, $issue))->toBe(ProjectAccessLevel::Viewer)
        ->and($this->access->levelFor($this->outsider, $issue))->toBeNull()
        ->and($this->access->levelFor($this->admin, $issue))->toBe(ProjectAccessLevel::Admin)
        ->and($this->stranger->can('view', $issue))->toBeTrue()
        ->and($this->outsider->can('view', $issue))->toBeFalse()
        ->and($this->stranger->can('update', $issue))->toBeFalse();
});

test('issues in private projects are hidden unless you take part', function () {
    $project = Project::factory()->private()->create(['organization_id' => $this->organization->id, 'lead_id' => $this->member->id]);
    $project->teams()->attach($this->design->id);

    $issue = $this->actions->create($this->organization, ['title' => 'Secret', 'project' => $project], $this->admin);

    expect($this->access->levelFor($this->stranger, $issue))->toBeNull()
        ->and($this->access->levelFor($this->member, $issue))->toBe(ProjectAccessLevel::Manager)   // project lead
        ->and($this->access->levelFor($this->other, $issue))->toBe(ProjectAccessLevel::Viewer);  // via Design on the project

    expect(Issue::query()->visibleTo($this->stranger)->pluck('id'))->not->toContain($issue->id)
        ->and(Issue::query()->visibleTo($this->other)->pluck('id'))->toContain($issue->id);
});

test('a team participating in an issue sees it even inside a private project, without seeing the project itself', function () {
    $project = Project::factory()->private()->create(['organization_id' => $this->organization->id, 'lead_id' => $this->admin->id]);
    $issue = $this->actions->create($this->organization, ['title' => 'Cross-team', 'project' => $project], $this->admin, [['team' => $this->design]]);

    expect($this->other->can('view', $issue))->toBeTrue()
        ->and($this->other->can('view', $project))->toBeFalse()
        ->and(Issue::query()->visibleTo($this->other)->pluck('id'))->toContain($issue->id);
});

test('workstream participants can change their own workstream but not global properties', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Shared', 'product' => $this->product, 'owner' => $this->admin], $this->admin, [['team' => $this->design], ['team' => $this->engineering]]);
    $designStream = $issue->workstreams()->where('team_id', $this->design->id)->first();
    $engineeringStream = $issue->workstreams()->where('team_id', $this->engineering->id)->first();

    expect($this->other->can('updateWorkstream', [$issue, $designStream]))->toBeTrue()
        ->and($this->other->can('updateWorkstream', [$issue, $engineeringStream]))->toBeFalse()
        ->and($this->other->can('update', $issue))->toBeFalse()
        ->and($this->other->can('setStatus', $issue))->toBeFalse()
        ->and($this->other->can('comment', $issue))->toBeTrue();

    // The lead of the lead team manages the whole issue.
    $this->actions->setLeadTeam($issue, $this->engineering, $this->admin);
    expect($this->member->can('update', $issue))->toBeTrue();
});

test('the SQL visibility scope agrees with the resolver for a mix of issues', function () {
    $public = Project::factory()->create(['organization_id' => $this->organization->id]);
    $private = Project::factory()->private()->create(['organization_id' => $this->organization->id, 'lead_id' => $this->admin->id]);

    $issues = [
        $this->actions->create($this->organization, ['title' => 'A', 'product' => $this->product], $this->admin),
        $this->actions->create($this->organization, ['title' => 'B', 'project' => $public], $this->admin),
        $this->actions->create($this->organization, ['title' => 'C', 'project' => $private], $this->admin),
        $this->actions->create($this->organization, ['title' => 'D', 'project' => $private], $this->admin, [['team' => $this->design]]),
        $this->actions->create($this->organization, ['title' => 'E', 'project' => $private, 'owner' => $this->stranger], $this->admin),
        $this->actions->create($this->organization, ['title' => 'F'], $this->stranger),
    ];

    foreach ([$this->admin, $this->member, $this->other, $this->stranger] as $user) {
        $this->access->flush();
        $expected = collect($issues)->filter(fn (Issue $i) => $this->access->levelFor($user, $i) !== null)->pluck('id')->sort()->values()->all();
        $actual = Issue::query()->visibleTo($user)->pluck('id')->sort()->values()->all();

        expect($actual)->toBe($expected, "visibility mismatch for user {$user->id}");
    }
});

test('following an issue grants access to it', function () {
    $private = Project::factory()->private()->create(['organization_id' => $this->organization->id, 'lead_id' => $this->admin->id]);
    $issue = $this->actions->create($this->organization, ['title' => 'Followed', 'project' => $private], $this->admin);

    expect($this->stranger->can('view', $issue))->toBeFalse();

    $this->actions->follow($issue, $this->stranger, true, $this->admin);
    $this->access->flush();

    expect($this->stranger->can('view', $issue))->toBeTrue();
});

test('team workflows and the org workflow coexist on one issue', function () {
    $workflows = app(WorkflowActions::class);
    $issue = $this->actions->create($this->organization, ['title' => 'Two workflows', 'product' => $this->product], $this->admin, [['team' => $this->engineering]]);
    $stream = $issue->workstreams()->first();

    expect($stream->status->workflow_id)->toBe($workflows->forTeam($this->engineering)->id)
        ->and($issue->status->workflow_id)->toBe($workflows->defaultIssueWorkflow($this->organization)->id)
        ->and($stream->status->workflow_id)->not->toBe($issue->status->workflow_id);

    $wrong = $workflows->defaultIssueWorkflow($this->organization)->statusFor(StatusCategory::Started);

    expect(fn () => $this->actions->setWorkstreamStatus($stream, $wrong, $this->member))->toThrow(ValidationException::class);
});
