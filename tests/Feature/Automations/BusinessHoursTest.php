<?php

use App\Enums\AutomationTrigger;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Organization;
use App\Services\Automations\BusinessHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'business_hours' => [
            'timezone' => 'Europe/Amsterdam',
            'days' => [
                'mon' => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'tue' => [['start' => '09:00', 'end' => '17:00']],
                'wed' => [['start' => '09:00', 'end' => '17:00']],
                'thu' => [['start' => '09:00', 'end' => '17:00']],
                'fri' => [['start' => '09:00', 'end' => '16:00']],
                'sat' => [],
                'sun' => [],
            ],
            'holidays' => ['2026-12-25'],
        ],
    ]);

    $this->hours = app(BusinessHours::class);
    $this->at = fn (string $time) => CarbonImmutable::parse($time, 'Europe/Amsterdam');
});

afterEach(fn () => Carbon::setTestNow());

test('isOpen honours segments, weekends, holidays and timezones', function () {
    expect($this->hours->isOpen($this->organization, ($this->at)('2026-11-02 10:00')))->toBeTrue()
        ->and($this->hours->isOpen($this->organization, ($this->at)('2026-11-02 12:30')))->toBeFalse()
        ->and($this->hours->isOpen($this->organization, ($this->at)('2026-11-02 13:00')))->toBeTrue()
        ->and($this->hours->isOpen($this->organization, ($this->at)('2026-11-02 17:00')))->toBeFalse()
        ->and($this->hours->isOpen($this->organization, ($this->at)('2026-11-02 08:59')))->toBeFalse()
        ->and($this->hours->isOpen($this->organization, ($this->at)('2026-11-07 10:00')))->toBeFalse()
        ->and($this->hours->isOpen($this->organization, ($this->at)('2026-12-25 10:00')))->toBeFalse()
        ->and($this->hours->isOpen($this->organization, CarbonImmutable::parse('2026-11-02 08:30', 'UTC')))->toBeTrue()
        ->and($this->hours->isOpen($this->organization, CarbonImmutable::parse('2026-11-02 16:30', 'UTC')))->toBeFalse();
});

test('organizations without a schedule are always open', function () {
    $organization = Organization::factory()->create(['business_hours' => null]);
    $empty = Organization::factory()->create(['business_hours' => ['days' => ['mon' => [], 'sun' => []]]]);

    expect($this->hours->hasSchedule($organization))->toBeFalse()
        ->and($this->hours->isOpen($organization, ($this->at)('2026-11-08 03:00')))->toBeTrue()
        ->and($this->hours->isOpen($empty, ($this->at)('2026-11-08 03:00')))->toBeTrue()
        ->and($this->hours->transitionsBetween($organization, ($this->at)('2026-11-02 08:00'), ($this->at)('2026-11-02 10:00')))->toBe([])
        ->and($this->hours->nextBusinessDay($organization, ($this->at)('2026-11-06 10:00'))->toDateString())->toBe('2026-11-09');
});

test('nextOpening and nextBusinessDay look past closed days', function () {
    expect($this->hours->nextOpening($this->organization, ($this->at)('2026-11-02 10:00'))->format('Y-m-d H:i'))->toBe('2026-11-02 10:00')
        ->and($this->hours->nextOpening($this->organization, ($this->at)('2026-11-02 12:30'))->format('Y-m-d H:i'))->toBe('2026-11-02 13:00')
        ->and($this->hours->nextOpening($this->organization, ($this->at)('2026-11-07 10:00'))->format('Y-m-d H:i'))->toBe('2026-11-09 09:00')
        ->and($this->hours->nextOpening($this->organization, ($this->at)('2026-12-25 10:00'))->format('Y-m-d H:i'))->toBe('2026-12-28 09:00')
        ->and($this->hours->nextBusinessDay($this->organization, ($this->at)('2026-11-06 10:00'))->toDateString())->toBe('2026-11-09')
        ->and($this->hours->nextBusinessDay($this->organization, ($this->at)('2026-12-24 10:00'))->toDateString())->toBe('2026-12-28');
});

test('transitionsBetween reports every open and close inside the window', function () {
    $transitions = $this->hours->transitionsBetween($this->organization, ($this->at)('2026-11-02 08:55'), ($this->at)('2026-11-02 13:05'));

    expect($transitions)->toHaveCount(3)
        ->and($transitions[0]['open'])->toBeTrue()
        ->and($transitions[0]['at']->format('H:i'))->toBe('09:00')
        ->and($transitions[1]['open'])->toBeFalse()
        ->and($transitions[1]['at']->format('H:i'))->toBe('12:00')
        ->and($transitions[2]['open'])->toBeTrue()
        ->and($transitions[2]['at']->format('H:i'))->toBe('13:00')
        ->and($this->hours->transitionsBetween($this->organization, ($this->at)('2026-11-02 10:00'), ($this->at)('2026-11-02 10:30')))->toBe([]);
});

test('the scheduled command fires business-hours triggers on transitions for open conversations only', function () {
    $inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $open = Conversation::factory()->create(['inbox_id' => $inbox->id]);
    $closed = Conversation::factory()->closed()->create(['inbox_id' => $inbox->id]);
    $other = Conversation::factory()->create();

    $ended = AutomationRule::factory()->create([
        'organization_id' => $this->organization->id,
        'trigger' => AutomationTrigger::BusinessHoursEnded,
        'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'after-hours']]],
    ]);
    $started = AutomationRule::factory()->create([
        'organization_id' => $this->organization->id,
        'trigger' => AutomationTrigger::BusinessHoursStarted,
        'actions' => [['type' => 'remove_tag', 'params' => ['tag_name' => 'after-hours']]],
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-02 16:58', 'Europe/Amsterdam'));
    $this->artisan('automations:business-hours')->assertSuccessful();

    expect(AutomationRun::query()->count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-11-02 16:59', 'Europe/Amsterdam'));
    $this->artisan('automations:business-hours')->assertSuccessful();

    expect(AutomationRun::query()->count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-11-02 17:01', 'Europe/Amsterdam'));
    $this->artisan('automations:business-hours')->assertSuccessful();

    expect($open->tags()->pluck('slug')->all())->toBe(['after-hours'])
        ->and($closed->tags()->count())->toBe(0)
        ->and($other->tags()->count())->toBe(0)
        ->and($ended->fresh()->run_count)->toBe(1)
        ->and($started->fresh()->run_count)->toBe(0)
        ->and(AutomationRun::query()->where('trigger', 'business_hours_ended')->where('conversation_id', $open->id)->value('status'))->toBe('matched');

    Carbon::setTestNow(Carbon::parse('2026-11-03 09:01', 'Europe/Amsterdam'));
    $this->artisan('automations:business-hours')->assertSuccessful();

    expect($open->tags()->count())->toBe(0)
        ->and($started->fresh()->run_count)->toBe(1);
});
