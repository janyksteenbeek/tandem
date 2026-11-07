<?php

use App\Enums\Projects\StatusCategory;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Services\Projects\IssueActions;
use App\Services\Projects\Reporting;
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
    $this->engineering = $w['engineering'];
    $this->design = $w['design'];
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $this->actions = app(IssueActions::class);
    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, ...$parameters]);
});

test('issue metrics count each issue once however many teams work on it, while team metrics count workstreams', function () {
    $project = Project::factory()->forProduct($this->product)->create();

    $shared = $this->actions->create($this->organization, ['title' => 'Shared', 'product' => $this->product, 'project' => $project], $this->admin, [['team' => $this->design], ['team' => $this->engineering]]);
    $solo = $this->actions->create($this->organization, ['title' => 'Solo', 'product' => $this->product, 'project' => $project], $this->admin, [['team' => $this->engineering]]);

    $engineeringDone = app(WorkflowActions::class)->forTeam($this->engineering)->statusFor(StatusCategory::Completed);
    $this->actions->setWorkstreamStatus($solo->workstreams()->first(), $engineeringDone, $this->member);

    $report = app(Reporting::class)->organization($this->organization, $this->admin);

    expect($report['issues']['open'])->toBe(1)
        ->and($report['issues']['completed'])->toBe(1)
        ->and($report['cross_team']['multi_team_issues'])->toBe(1)
        ->and(collect($report['teams'])->firstWhere('id', $this->engineering->id)['open_workstreams'])->toBe(1)
        ->and(collect($report['teams'])->firstWhere('id', $this->design->id)['open_workstreams'])->toBe(1);

    $teamReport = app(Reporting::class)->team($this->engineering, $this->admin);
    expect($teamReport['workstreams']['completed_since'])->toBe(1)->and($teamReport['workstreams']['open'])->toBe(1);

    $projectReport = app(Reporting::class)->project($project, $this->admin);
    expect($projectReport['progress']['total'])->toBe(2)->and($projectReport['progress']['completed'])->toBe(1);
});

test('reports respect visibility and scope', function () {
    $private = Project::factory()->private()->create(['organization_id' => $this->organization->id, 'lead_id' => $this->admin->id]);
    $this->actions->create($this->organization, ['title' => 'Hidden', 'project' => $private], $this->admin);
    $this->actions->create($this->organization, ['title' => 'Visible', 'product' => $this->product], $this->admin);

    expect(app(Reporting::class)->organization($this->organization, $this->other)['issues']['open'])->toBe(1)
        ->and(app(Reporting::class)->organization($this->organization, $this->admin)['issues']['open'])->toBe(2);

    $this->actingAs($this->other)->get(($this->url)('projects.reports.index', ['scope' => 'project', 'id' => $private->slug]))->assertForbidden();

    $this->actingAs($this->other)
        ->get(($this->url)('projects.reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('projects/reports/Index')->where('scope.type', 'organization'));

    $this->actingAs($this->admin)
        ->getJson(($this->url)('projects.reports.data', ['scope' => 'product', 'id' => $this->product->slug]))
        ->assertOk()
        ->assertJsonPath('report.issues.open', 1);
});
