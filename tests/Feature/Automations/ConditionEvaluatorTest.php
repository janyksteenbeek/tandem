<?php

use App\Enums\ConversationPriority;
use App\Enums\OrganizationRole;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use App\Services\Automations\ConditionEvaluator;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'business_hours' => [
            'timezone' => 'Europe/Amsterdam',
            'days' => [
                'mon' => [['start' => '09:00', 'end' => '17:00']],
                'tue' => [['start' => '09:00', 'end' => '17:00']],
                'wed' => [['start' => '09:00', 'end' => '17:00']],
                'thu' => [['start' => '09:00', 'end' => '17:00']],
                'fri' => [['start' => '09:00', 'end' => '17:00']],
                'sat' => [],
                'sun' => [],
            ],
            'holidays' => ['2026-12-25'],
        ],
    ]);
    $this->owner = User::factory()->create();
    $this->organization->members()->attach($this->owner, ['role' => OrganizationRole::Owner->value]);

    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Support']);
    $this->conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'subject' => 'Invoice INV-42 is wrong', 'priority' => ConversationPriority::High]);
    $this->message = Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'from' => ['name' => 'Ada Lovelace', 'email' => 'ada@customer.example'],
        'to' => [['name' => null, 'email' => 'billing@acme.test']],
        'cc' => [['name' => null, 'email' => 'cfo@customer.example']],
        'text_body' => 'Please refund the duplicate charge.',
    ]);

    $this->evaluator = app(ConditionEvaluator::class);
    $this->evaluate = fn (array $conditions, string $match = 'all') => $this->evaluator->evaluate($conditions, $match, $this->conversation, ['message' => $this->message]);
});

afterEach(fn () => Carbon::setTestNow());

test('sender conditions match exact addresses and domains', function () {
    expect(($this->evaluate)([['field' => 'sender', 'operator' => 'equals', 'value' => '@customer.example']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'sender', 'operator' => 'equals', 'value' => 'customer.example']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'sender', 'operator' => 'equals', 'value' => 'ADA@customer.example']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'sender', 'operator' => 'equals', 'value' => '@other.example']])['matched'])->toBeFalse()
        ->and(($this->evaluate)([['field' => 'sender', 'operator' => 'not_equals', 'value' => '@other.example']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'sender', 'operator' => 'in', 'value' => ['@bigcorp.com', '@customer.example']]])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'sender', 'operator' => 'not_in', 'value' => ['@bigcorp.com']]])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'sender', 'operator' => 'ends_with', 'value' => '.example']])['matched'])->toBeTrue();
});

test('recipient conditions match any to or cc address', function () {
    expect(($this->evaluate)([['field' => 'recipient', 'operator' => 'equals', 'value' => 'billing@acme.test']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'recipient', 'operator' => 'equals', 'value' => '@customer.example']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'recipient', 'operator' => 'equals', 'value' => 'sales@acme.test']])['matched'])->toBeFalse();
});

test('subject and body text operators are case insensitive', function () {
    expect(($this->evaluate)([['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'subject', 'operator' => 'starts_with', 'value' => 'INVOICE']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'subject', 'operator' => 'not_contains', 'value' => 'invoice']])['matched'])->toBeFalse()
        ->and(($this->evaluate)([['field' => 'subject', 'operator' => 'matches_regex', 'value' => 'INV-\d+']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'subject', 'operator' => 'matches_regex', 'value' => '/^invoice/i']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'subject', 'operator' => 'matches_regex', 'value' => '(unclosed']])['matched'])->toBeFalse()
        ->and(($this->evaluate)([['field' => 'body', 'operator' => 'contains', 'value' => 'refund']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'body', 'operator' => 'is_set', 'value' => null]])['matched'])->toBeTrue();
});

test('tag conditions compare slugs regardless of casing', function () {
    $tag = Tag::factory()->create(['organization_id' => $this->organization->id, 'name' => 'VIP Customer']);
    $this->conversation->tags()->attach($tag);

    expect(($this->evaluate)([['field' => 'tag', 'operator' => 'equals', 'value' => 'VIP Customer']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'tag', 'operator' => 'equals', 'value' => 'vip-customer']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'tag', 'operator' => 'in', 'value' => ['billing', 'vip-customer']]])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'tag', 'operator' => 'equals', 'value' => 'billing']])['matched'])->toBeFalse()
        ->and(($this->evaluate)([['field' => 'tag', 'operator' => 'is_empty', 'value' => null]])['matched'])->toBeFalse();
});

test('assignee status priority and inbox conditions use identifiers and enum keys', function () {
    expect(($this->evaluate)([['field' => 'assignee', 'operator' => 'is_empty', 'value' => null]])['matched'])->toBeTrue();

    $this->conversation->forceFill(['assignee_id' => $this->owner->id])->save();

    expect(($this->evaluate)([['field' => 'assignee', 'operator' => 'equals', 'value' => (string) $this->owner->id]])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'assignee', 'operator' => 'is_set', 'value' => null]])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'status', 'operator' => 'equals', 'value' => 'open']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'status', 'operator' => 'in', 'value' => ['closed', 'pending']]])['matched'])->toBeFalse()
        ->and(($this->evaluate)([['field' => 'priority', 'operator' => 'equals', 'value' => 'high']])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'priority', 'operator' => 'not_in', 'value' => ['urgent', 'high']]])['matched'])->toBeFalse()
        ->and(($this->evaluate)([['field' => 'inbox', 'operator' => 'equals', 'value' => $this->inbox->uuid]])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'inbox', 'operator' => 'not_equals', 'value' => $this->inbox->uuid]])['matched'])->toBeFalse();
});

test('business hours conditions follow the organization schedule', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'Europe/Amsterdam'));

    expect(($this->evaluate)([['field' => 'business_hours', 'operator' => 'is_true', 'value' => null]])['matched'])->toBeTrue()
        ->and(($this->evaluate)([['field' => 'business_hours', 'operator' => 'is_false', 'value' => null]])['matched'])->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-11-02 20:00', 'Europe/Amsterdam'));

    expect(($this->evaluate)([['field' => 'business_hours', 'operator' => 'is_false', 'value' => null]])['matched'])->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-12-25 10:00', 'Europe/Amsterdam'));

    expect(($this->evaluate)([['field' => 'business_hours', 'operator' => 'is_true', 'value' => null]])['matched'])->toBeFalse();
});

test('match all requires every condition while match any needs one', function () {
    $conditions = [
        ['field' => 'sender', 'operator' => 'equals', 'value' => '@customer.example'],
        ['field' => 'subject', 'operator' => 'contains', 'value' => 'password reset'],
    ];

    $all = ($this->evaluate)($conditions, 'all');
    $any = ($this->evaluate)($conditions, 'any');

    expect($all['matched'])->toBeFalse()
        ->and($any['matched'])->toBeTrue()
        ->and($all['conditions'])->toHaveCount(2)
        ->and($all['conditions'][0]['passed'])->toBeTrue()
        ->and($all['conditions'][0]['actual'])->toBe('ada@customer.example')
        ->and($all['conditions'][1]['passed'])->toBeFalse()
        ->and(($this->evaluate)([])['matched'])->toBeTrue();
});

test('unsupported operators and unknown fields fail with an explanation', function () {
    $result = ($this->evaluate)([
        ['field' => 'business_hours', 'operator' => 'contains', 'value' => 'x'],
        ['field' => 'moon_phase', 'operator' => 'equals', 'value' => 'full'],
    ], 'any');

    expect($result['matched'])->toBeFalse()
        ->and($result['conditions'][0]['error'])->toContain('not supported')
        ->and($result['conditions'][1]['error'])->toContain('Unknown field');
});
