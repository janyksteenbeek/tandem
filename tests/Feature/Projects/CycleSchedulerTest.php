<?php

use App\Enums\Projects\CycleStatus;
use App\Enums\Projects\StatusCategory;
use App\Models\Projects\Cycle;
use App\Models\Projects\Product;
use App\Notifications\Projects\CycleStartedNotification;
use App\Services\Projects\CycleScheduler;
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
    $this->scheduler = app(CycleScheduler::class);
    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, ...$parameters]);
});

test('cycles are numbered per team and each team schedules the same issue independently', function () {
    $engineeringCycle = $this->scheduler->create($this->engineering, ['starts_at' => now()->toDateString(), 'ends_at' => now()->addWeeks(2)->toDateString()], $this->admin);
    $designCycle = $this->scheduler->create($this->design, ['starts_at' => now()->toDateString(), 'ends_at' => now()->addWeeks(2)->toDateString()], $this->admin);
    $second = $this->scheduler->create($this->engineering, ['starts_at' => now()->addWeeks(2)->toDateString(), 'ends_at' => now()->addWeeks(4)->toDateString()], $this->admin);

    expect([$engineeringCycle->number, $designCycle->number, $second->number])->toBe([1, 1, 2]);

    $issue = $this->actions->create($this->organization, ['title' => 'Shared', 'product' => $this->product], $this->admin, [['team' => $this->design], ['team' => $this->engineering]]);
    $designStream = $issue->workstreams()->where('team_id', $this->design->id)->first();
    $engineeringStream = $issue->workstreams()->where('team_id', $this->engineering->id)->first();

    $this->actions->setWorkstreamCycle($designStream, $designCycle, $this->other);
    $this->actions->setWorkstreamCycle($engineeringStream, $second, $this->member);

    expect($designStream->fresh()->cycle_id)->toBe($designCycle->id)
        ->and($engineeringStream->fresh()->cycle_id)->toBe($second->id)
        ->and(fn () => $this->actions->setWorkstreamCycle($designStream, $engineeringCycle, $this->other))->toThrow(ValidationException::class);
});

test('completing a cycle carries open work into the next one and keeps velocity', function () {
    $this->engineering->forceFill(['cycle_settings' => ['enabled' => true, 'length_weeks' => 2, 'auto_carry_over' => true, 'upcoming_count' => 1]])->save();

    $cycle = $this->scheduler->create($this->engineering, ['starts_at' => now()->subWeeks(2)->toDateString(), 'ends_at' => now()->subDay()->toDateString()], $this->admin);
    $this->scheduler->start($cycle, $this->admin);

    Notification::assertSentTo($this->member, CycleStartedNotification::class);

    $done = $this->actions->create($this->organization, ['title' => 'Done', 'product' => $this->product], $this->admin, [['team' => $this->engineering]]);
    $open = $this->actions->create($this->organization, ['title' => 'Open', 'product' => $this->product], $this->admin, [['team' => $this->engineering]]);

    $this->actions->setWorkstreamCycle($done->workstreams()->first(), $cycle, $this->member);
    $this->actions->setWorkstreamCycle($open->workstreams()->first(), $cycle, $this->member);
    $this->actions->setWorkstreamStatus($done->workstreams()->first(), app(WorkflowActions::class)->forTeam($this->engineering)->statusFor(StatusCategory::Completed), $this->member);

    $this->scheduler->complete($cycle, $this->admin);

    $cycle->refresh();
    $next = Cycle::query()->where('team_id', $this->engineering->id)->where('number', 2)->first();

    expect($cycle->status)->toBe(CycleStatus::Completed)
        ->and($cycle->carried_over_count)->toBe(1)
        ->and($cycle->completed_workstreams_count)->toBe(1)
        ->and($next)->not->toBeNull()
        ->and($open->workstreams()->first()->cycle_id)->toBe($next->id)
        ->and($done->workstreams()->first()->cycle_id)->toBe($cycle->id);

    $this->assertDatabaseHas('issue_activities', ['issue_id' => $open->id, 'type' => 'cycle_carried_over']);

    $velocity = $this->scheduler->velocity($this->engineering);
    expect($velocity)->toHaveCount(1)->and($velocity[0]['completed'])->toBe(1);
});

test('the scheduler tick starts due cycles, completes ended ones and keeps upcoming ones', function () {
    $this->engineering->forceFill(['cycle_settings' => ['enabled' => true, 'length_weeks' => 1, 'cooldown_weeks' => 0, 'upcoming_count' => 2, 'auto_carry_over' => false]])->save();

    $ended = $this->scheduler->create($this->engineering, ['starts_at' => now()->subWeeks(2)->toDateString(), 'ends_at' => now()->subWeek()->toDateString()], $this->admin);
    $ended->forceFill(['status' => CycleStatus::Active, 'started_at' => now()->subWeeks(2)])->save();
    $due = $this->scheduler->create($this->engineering, ['starts_at' => now()->subDay()->toDateString(), 'ends_at' => now()->addDays(5)->toDateString()], $this->admin);

    $stats = $this->scheduler->tick();

    expect($stats['completed'])->toBe(1)
        ->and($stats['started'])->toBe(1)
        ->and($ended->fresh()->status)->toBe(CycleStatus::Completed)
        ->and($due->fresh()->status)->toBe(CycleStatus::Active)
        ->and(Cycle::query()->where('team_id', $this->engineering->id)->where('status', CycleStatus::Upcoming->value)->count())->toBe(2);

    $this->artisan('projects:cycles')->assertSuccessful();
});

test('team leads manage cycles over HTTP and members only read them', function () {
    $this->actingAs($this->other)
        ->postJson(($this->url)('projects.teams.cycles.store', ['team' => $this->engineering->slug]), ['starts_at' => now()->toDateString(), 'ends_at' => now()->addWeek()->toDateString()])
        ->assertForbidden();

    $response = $this->actingAs($this->member)
        ->postJson(($this->url)('projects.teams.cycles.store', ['team' => $this->engineering->slug]), ['name' => 'Sprint 1', 'starts_at' => now()->toDateString(), 'ends_at' => now()->addWeek()->toDateString()])
        ->assertCreated()
        ->assertJsonPath('cycle.name', 'Sprint 1');

    $uuid = $response->json('cycle.uuid');

    $this->actingAs($this->member)->postJson(($this->url)('projects.teams.cycles.start', ['team' => $this->engineering->slug, 'cycle' => $uuid]))->assertOk()->assertJsonPath('cycle.status', 'active');
    $this->actingAs($this->other)->postJson(($this->url)('projects.teams.cycles.complete', ['team' => $this->engineering->slug, 'cycle' => $uuid]))->assertForbidden();
    $this->actingAs($this->member)->postJson(($this->url)('projects.teams.cycles.complete', ['team' => $this->engineering->slug, 'cycle' => $uuid]), ['carry_over' => false])->assertOk()->assertJsonPath('cycle.status', 'completed');
});
