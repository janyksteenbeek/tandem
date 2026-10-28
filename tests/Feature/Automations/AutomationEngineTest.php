<?php

use App\Enums\AutomationTrigger;
use App\Enums\ConversationStatus;
use App\Enums\OrganizationRole;
use App\Events\Conversations\ConversationAssigned;
use App\Events\Conversations\ConversationStatusChanged;
use App\Events\Conversations\ConversationTagged;
use App\Events\Mail\MessageIngested;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use App\Services\Automations\AutomationEngine;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->owner = User::factory()->create();
    $this->agent = User::factory()->create();
    $this->organization->members()->attach($this->owner, ['role' => OrganizationRole::Owner->value]);
    $this->organization->members()->attach($this->agent, ['role' => OrganizationRole::Member->value]);

    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $this->conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'subject' => 'Invoice overdue']);
    $this->message = Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'from' => ['name' => 'Ada', 'email' => 'ada@customer.example'],
    ]);

    $this->engine = app(AutomationEngine::class);

    $this->rule = fn (array $attributes = []) => AutomationRule::factory()->create(array_merge([
        'organization_id' => $this->organization->id,
        'created_by_id' => $this->owner->id,
        'trigger' => AutomationTrigger::ConversationReceived,
        'conditions' => [],
        'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'auto']]],
    ], $attributes));
});

test('a matched rule performs its actions and writes a full audit trail', function () {
    $rule = ($this->rule)([
        'name' => 'Tag invoices',
        'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice']],
        'actions' => [
            ['type' => 'add_tag', 'params' => ['tag_name' => 'Invoices']],
            ['type' => 'set_priority', 'params' => ['priority' => 'high']],
            ['type' => 'assign_user', 'params' => ['user_id' => $this->agent->id]],
        ],
    ]);

    $this->engine->run(AutomationTrigger::ConversationReceived, $this->conversation, ['message' => $this->message]);

    $fresh = $this->conversation->fresh();
    $run = AutomationRun::query()->where('automation_rule_id', $rule->id)->first();

    expect($fresh->tags()->pluck('slug')->all())->toBe(['invoices'])
        ->and($fresh->priority->key())->toBe('high')
        ->and($fresh->assignee_id)->toBe($this->agent->id)
        ->and($rule->fresh()->run_count)->toBe(1)
        ->and($rule->fresh()->last_run_at)->not->toBeNull()
        ->and($run->status)->toBe('matched')
        ->and($run->trigger)->toBe('conversation_received')
        ->and($run->conversation_id)->toBe($this->conversation->id)
        ->and($run->details['conditions'][0])->toMatchArray(['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice', 'actual' => 'Invoice overdue', 'passed' => true])
        ->and(collect($run->details['actions'])->pluck('type')->all())->toBe(['add_tag', 'set_priority', 'assign_user'])
        ->and(collect($run->details['actions'])->pluck('status')->unique()->all())->toBe(['done']);

    $event = $fresh->events()->where('type', 'automation_ran')->first();

    expect($event)->not->toBeNull()
        ->and($event->actor_id)->toBeNull()
        ->and($event->payload['rule_id'])->toBe($rule->id)
        ->and($event->payload['rule_name'])->toBe('Tag invoices')
        ->and($event->payload['actions'])->toHaveCount(3);
});

test('rules whose conditions fail are audited as skipped', function () {
    $rule = ($this->rule)(['conditions' => [['field' => 'sender', 'operator' => 'equals', 'value' => '@bigcorp.com']]]);

    $this->engine->run(AutomationTrigger::ConversationReceived, $this->conversation, ['message' => $this->message]);

    $run = AutomationRun::query()->where('automation_rule_id', $rule->id)->first();

    expect($run->status)->toBe('skipped')
        ->and($run->details['reason'])->toBe('conditions_not_met')
        ->and($run->details['conditions'][0]['passed'])->toBeFalse()
        ->and($rule->fresh()->run_count)->toBe(0)
        ->and($this->conversation->tags()->count())->toBe(0)
        ->and($this->conversation->events()->where('type', 'automation_ran')->exists())->toBeFalse();
});

test('a failing rule is rolled back and audited without stopping the next rule', function () {
    $broken = ($this->rule)([
        'name' => 'Broken',
        'position' => 1,
        'actions' => [
            ['type' => 'add_tag', 'params' => ['tag_name' => 'partial']],
            ['type' => 'explode', 'params' => []],
        ],
    ]);
    $healthy = ($this->rule)(['name' => 'Healthy', 'position' => 2, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'healthy']]]]);

    $this->engine->run(AutomationTrigger::ConversationReceived, $this->conversation, ['message' => $this->message]);

    $failed = AutomationRun::query()->where('automation_rule_id', $broken->id)->first();

    expect($failed->status)->toBe('failed')
        ->and($failed->details['error'])->toContain('explode')
        ->and($failed->details['actions'])->toHaveCount(1)
        ->and($broken->fresh()->run_count)->toBe(0)
        ->and($this->conversation->tags()->pluck('slug')->all())->toBe(['healthy'])
        ->and(AutomationRun::query()->where('automation_rule_id', $healthy->id)->value('status'))->toBe('matched');
});

test('only active rules for the trigger and the owning inbox run, in position order', function () {
    $other = Inbox::factory()->create(['organization_id' => $this->organization->id]);

    $inactive = ($this->rule)(['is_active' => false, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'inactive']]]]);
    $wrongTrigger = ($this->rule)(['trigger' => AutomationTrigger::ConversationClosed, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'closed']]]]);
    $otherInbox = ($this->rule)(['inbox_id' => $other->id, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'other']]]]);
    $second = ($this->rule)(['position' => 2, 'inbox_id' => $this->inbox->id, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'second']]]]);
    $first = ($this->rule)(['position' => 1, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'first']]]]);
    AutomationRule::factory()->create(['trigger' => AutomationTrigger::ConversationReceived, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'foreign']]]]);

    $this->engine->run(AutomationTrigger::ConversationReceived, $this->conversation, ['message' => $this->message]);

    expect($this->conversation->tags()->orderBy('conversation_tag.id')->pluck('slug')->all())->toBe(['first', 'second'])
        ->and(AutomationRun::query()->whereIn('automation_rule_id', [$inactive->id, $wrongTrigger->id, $otherInbox->id])->exists())->toBeFalse()
        ->and(AutomationRun::query()->count())->toBe(2)
        ->and($first->fresh()->run_count)->toBe(1)
        ->and($second->fresh()->run_count)->toBe(1);
});

test('conversations in personal inboxes are ignored', function () {
    $personal = Inbox::factory()->personal($this->owner)->create();
    $conversation = Conversation::factory()->create(['inbox_id' => $personal->id, 'organization_id' => null]);
    ($this->rule)();

    $this->engine->run(AutomationTrigger::ConversationReceived, $conversation);

    expect(AutomationRun::query()->count())->toBe(0)
        ->and($conversation->tags()->count())->toBe(0);
});

test('a rule cooldown prevents matching the same conversation twice', function () {
    $rule = ($this->rule)(['cooldown_minutes' => 60, 'actions' => [['type' => 'add_comment', 'params' => ['body' => 'ping']]]]);

    $this->engine->run(AutomationTrigger::ConversationReceived, $this->conversation, ['message' => $this->message]);
    $this->engine->run(AutomationTrigger::ConversationReceived, $this->conversation, ['message' => $this->message]);

    expect($this->conversation->comments()->count())->toBe(1)
        ->and($rule->runs()->pluck('status')->all())->toBe(['matched', 'skipped'])
        ->and($rule->runs()->latest('id')->first()->details['reason'])->toBe('cooldown');
});

test('message ingested events run new-conversation and reply rules but ignore outbound mail', function () {
    $received = ($this->rule)(['trigger' => AutomationTrigger::ConversationReceived, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'received']]]]);
    $replied = ($this->rule)(['trigger' => AutomationTrigger::ReplyReceived, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'replied']]]]);
    $reopened = ($this->rule)(['trigger' => AutomationTrigger::ConversationReopened, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'reopened']]]]);

    MessageIngested::dispatch($this->message, $this->conversation, true);

    expect($this->conversation->tags()->pluck('slug')->all())->toBe(['received'])
        ->and($received->fresh()->run_count)->toBe(1)
        ->and($replied->fresh()->run_count)->toBe(0);

    MessageIngested::dispatch($this->message, $this->conversation, false, true);

    expect($this->conversation->tags()->orderBy('conversation_tag.id')->pluck('slug')->all())->toBe(['received', 'replied', 'reopened'])
        ->and($reopened->fresh()->run_count)->toBe(1);

    $outbound = Message::factory()->outbound()->create(['conversation_id' => $this->conversation->id]);
    MessageIngested::dispatch($outbound, $this->conversation, false);

    expect($replied->fresh()->run_count)->toBe(1);
});

test('conversation events map onto their triggers', function () {
    $closed = ($this->rule)(['trigger' => AutomationTrigger::ConversationClosed, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'closed']]]]);
    $reopened = ($this->rule)(['trigger' => AutomationTrigger::ConversationReopened, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'reopened']]]]);
    $assigned = ($this->rule)(['trigger' => AutomationTrigger::ConversationAssigned, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'assigned']]]]);
    $tagged = ($this->rule)([
        'trigger' => AutomationTrigger::TagAdded,
        'conditions' => [['field' => 'tag', 'operator' => 'equals', 'value' => 'vip']],
        'actions' => [['type' => 'set_priority', 'params' => ['priority' => 'urgent']]],
    ]);

    ConversationStatusChanged::dispatch($this->conversation, ConversationStatus::Open, ConversationStatus::Closed, $this->owner);
    ConversationStatusChanged::dispatch($this->conversation, ConversationStatus::Closed, ConversationStatus::Open, $this->owner);
    ConversationStatusChanged::dispatch($this->conversation, ConversationStatus::Open, ConversationStatus::Pending, $this->owner);
    ConversationAssigned::dispatch($this->conversation, null, $this->agent, $this->owner);
    ConversationAssigned::dispatch($this->conversation, $this->agent, null, $this->owner);

    expect($closed->fresh()->run_count)->toBe(1)
        ->and($reopened->fresh()->run_count)->toBe(1)
        ->and($assigned->fresh()->run_count)->toBe(1);

    $vip = Tag::factory()->create(['organization_id' => $this->organization->id, 'name' => 'VIP']);
    $this->conversation->tags()->attach($vip);
    ConversationTagged::dispatch($this->conversation, $vip, $this->owner);

    expect($this->conversation->fresh()->priority->key())->toBe('urgent')
        ->and($tagged->fresh()->run_count)->toBe(1);
});

test('actions performed by the engine do not re-trigger other rules in the same run', function () {
    $assigner = ($this->rule)(['actions' => [['type' => 'assign_user', 'params' => ['user_id' => $this->agent->id]], ['type' => 'add_tag', 'params' => ['tag_name' => 'triage']]]]);
    $onAssign = ($this->rule)(['trigger' => AutomationTrigger::ConversationAssigned, 'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'escalated']]]]);
    $onTag = ($this->rule)(['trigger' => AutomationTrigger::TagAdded, 'actions' => [['type' => 'set_priority', 'params' => ['priority' => 'urgent']]]]);

    MessageIngested::dispatch($this->message, $this->conversation, true);

    expect($this->conversation->fresh()->assignee_id)->toBe($this->agent->id)
        ->and($this->conversation->tags()->pluck('slug')->all())->toBe(['triage'])
        ->and($this->conversation->fresh()->priority->key())->toBe('none')
        ->and($assigner->fresh()->run_count)->toBe(1)
        ->and($onAssign->fresh()->run_count)->toBe(0)
        ->and($onTag->fresh()->run_count)->toBe(0);

    ConversationAssigned::dispatch($this->conversation->fresh(), null, $this->owner, $this->owner);

    expect($onAssign->fresh()->run_count)->toBe(1);
});
