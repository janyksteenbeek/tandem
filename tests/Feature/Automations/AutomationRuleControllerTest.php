<?php

use App\Enums\AutomationTrigger;
use App\Enums\OrganizationRole;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();

    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->organization->members()->attach($this->admin, ['role' => OrganizationRole::Admin->value]);
    $this->organization->members()->attach($this->member, ['role' => OrganizationRole::Member->value]);

    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $this->tag = Tag::factory()->create(['organization_id' => $this->organization->id]);
    $this->team = Team::factory()->create(['organization_id' => $this->organization->id]);
    $this->template = MessageTemplate::create(['organization_id' => $this->organization->id, 'name' => 'OOO', 'body_html' => '<p>Hi</p>']);

    $this->rule = AutomationRule::factory()->create([
        'organization_id' => $this->organization->id,
        'inbox_id' => $this->inbox->id,
        'name' => 'Existing rule',
        'position' => 1,
        'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice']],
        'actions' => [['type' => 'add_tag', 'params' => ['tag_id' => $this->tag->id]]],
    ]);

    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, ...$parameters]);

    $this->payload = fn (array $overrides = []) => array_merge([
        'name' => 'Route billing',
        'description' => 'Billing mail to finance',
        'inbox_uuid' => $this->inbox->uuid,
        'trigger' => AutomationTrigger::ConversationReceived->value,
        'match' => 'any',
        'is_active' => true,
        'cooldown_minutes' => null,
        'conditions' => [
            ['field' => 'recipient', 'operator' => 'equals', 'value' => 'billing@acme.test'],
            ['field' => 'sender', 'operator' => 'in', 'value' => ['@bigcorp.com', '@acme.test']],
            ['field' => 'business_hours', 'operator' => 'is_false', 'value' => null],
        ],
        'actions' => [
            ['type' => 'assign_team', 'params' => ['team_id' => $this->team->id, 'strategy' => 'least_busy']],
            ['type' => 'add_tag', 'params' => ['tag_name' => 'billing']],
            ['type' => 'reply_with_template', 'params' => ['template_id' => $this->template->id, 'cooldown_minutes' => 120]],
            ['type' => 'notify_users', 'params' => ['user_ids' => [$this->admin->id], 'message' => 'Billing']],
        ],
    ], $overrides);
});

test('admins can view the automation pages with catalogs and related data', function () {
    $this->actingAs($this->admin)
        ->get(($this->url)('automations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/automations/Index', false)
            ->has('rules', 1, fn (Assert $rule) => $rule
                ->where('uuid', $this->rule->uuid)
                ->where('name', 'Existing rule')
                ->where('inbox.uuid', $this->inbox->uuid)
                ->where('run_count', 0)
                ->where('trigger.value', 'conversation_received')
                ->etc())
            ->has('catalog.triggers', count(AutomationTrigger::cases()))
            ->has('catalog.fields', 10)
            ->has('catalog.operators', 13)
            ->has('catalog.actions', 11)
            ->has('catalog.variables', 12));

    $this->actingAs($this->admin)
        ->get(($this->url)('automations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/automations/Edit', false)
            ->where('rule', null)
            ->has('inboxes', 1)
            ->has('tags', 1)
            ->has('teams', 1)
            ->has('templates', 1)
            ->has('users', 2)
            ->has('catalog'));

    AutomationRun::create(['automation_rule_id' => $this->rule->id, 'conversation_id' => null, 'trigger' => 'conversation_received', 'status' => 'skipped', 'details' => ['reason' => 'conditions_not_met']]);

    $this->actingAs($this->admin)
        ->get(($this->url)('automations.edit', ['rule' => $this->rule]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/automations/Edit', false)
            ->where('rule.uuid', $this->rule->uuid)
            ->where('rule.inbox_uuid', $this->inbox->uuid)
            ->has('rule.conditions', 1)
            ->has('rule.actions', 1)
            ->has('recentRuns', 1));
});

test('members and outsiders cannot manage automations', function () {
    $this->get(($this->url)('automations.index'))->assertRedirect(route('login'));

    $this->actingAs($this->member)->get(($this->url)('automations.index'))->assertForbidden();
    $this->actingAs($this->member)->post(($this->url)('automations.store'), ($this->payload)())->assertForbidden();
    $this->actingAs($this->member)->patch(($this->url)('automations.toggle', ['rule' => $this->rule]))->assertForbidden();

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get(($this->url)('automations.index'))->assertForbidden();
});

test('rules of another organization are not reachable', function () {
    $foreign = AutomationRule::factory()->create();

    $this->actingAs($this->admin)->get(($this->url)('automations.edit', ['rule' => $foreign]))->assertNotFound();
    $this->actingAs($this->admin)->delete(($this->url)('automations.destroy', ['rule' => $foreign]))->assertNotFound();
});

test('a valid rule can be stored and normalised', function () {
    $response = $this->actingAs($this->admin)->post(($this->url)('automations.store'), ($this->payload)());

    $rule = AutomationRule::query()->where('name', 'Route billing')->first();

    $response->assertRedirect(($this->url)('automations.edit', ['rule' => $rule]));

    expect($rule->organization_id)->toBe($this->organization->id)
        ->and($rule->inbox_id)->toBe($this->inbox->id)
        ->and($rule->created_by_id)->toBe($this->admin->id)
        ->and($rule->trigger)->toBe(AutomationTrigger::ConversationReceived)
        ->and($rule->match)->toBe('any')
        ->and($rule->position)->toBe(2)
        ->and($rule->conditions)->toBe([
            ['field' => 'recipient', 'operator' => 'equals', 'value' => 'billing@acme.test'],
            ['field' => 'sender', 'operator' => 'in', 'value' => ['@bigcorp.com', '@acme.test']],
            ['field' => 'business_hours', 'operator' => 'is_false', 'value' => null],
        ])
        ->and($rule->actions)->toHaveCount(4)
        ->and($rule->actions[0])->toBe(['type' => 'assign_team', 'params' => ['team_id' => $this->team->id, 'strategy' => 'least_busy']]);
});

test('store validates the json shapes strictly', function () {
    $outsider = User::factory()->create();
    $foreignInbox = Inbox::factory()->create();
    $foreignTemplate = MessageTemplate::create(['organization_id' => Organization::factory()->create()->id, 'name' => 'X', 'body_html' => '<p>x</p>']);

    $this->actingAs($this->admin)
        ->post(($this->url)('automations.store'), ($this->payload)([
            'name' => '',
            'trigger' => 'when_pigs_fly',
            'match' => 'some',
            'inbox_uuid' => $foreignInbox->uuid,
            'conditions' => [
                ['field' => 'business_hours', 'operator' => 'contains', 'value' => 'x'],
                ['field' => 'sender', 'operator' => 'equals', 'value' => ''],
                ['field' => 'sender', 'operator' => 'in', 'value' => 'not-an-array'],
                ['field' => 'subject', 'operator' => 'matches_regex', 'value' => '(unclosed'],
                ['field' => 'status', 'operator' => 'equals', 'value' => 'bogus'],
                ['field' => 'priority', 'operator' => 'equals', 'value' => 'bogus'],
            ],
            'actions' => [
                ['type' => 'assign_user', 'params' => ['user_id' => $outsider->id]],
                ['type' => 'assign_team', 'params' => ['team_id' => 999999, 'strategy' => 'random']],
                ['type' => 'move_to_inbox', 'params' => ['inbox_uuid' => $foreignInbox->uuid]],
                ['type' => 'add_tag', 'params' => []],
                ['type' => 'set_priority', 'params' => ['priority' => 'meh']],
                ['type' => 'set_status', 'params' => ['status' => 'snoozed']],
                ['type' => 'notify_users', 'params' => ['user_ids' => [$outsider->id]]],
                ['type' => 'add_comment', 'params' => ['body' => '']],
                ['type' => 'reply_with_template', 'params' => ['template_id' => $foreignTemplate->id]],
                ['type' => 'teleport', 'params' => []],
            ],
        ]))
        ->assertSessionHasErrors([
            'name', 'trigger', 'match', 'inbox_uuid',
            'conditions.0.operator', 'conditions.1.value', 'conditions.2.value', 'conditions.3.value', 'conditions.4.value', 'conditions.5.value',
            'actions.0.params.user_id', 'actions.1.params.team_id', 'actions.1.params.strategy', 'actions.2.params.inbox_uuid',
            'actions.3.params.tag_id', 'actions.4.params.priority', 'actions.5.params.status', 'actions.6.params.user_ids',
            'actions.7.params.body', 'actions.8.params.template_id', 'actions.9.type',
        ]);

    $this->actingAs($this->admin)
        ->post(($this->url)('automations.store'), ($this->payload)(['actions' => []]))
        ->assertSessionHasErrors(['actions']);

    expect(AutomationRule::query()->count())->toBe(1);
});

test('rules can be updated toggled reordered and deleted', function () {
    $second = AutomationRule::factory()->create(['organization_id' => $this->organization->id, 'position' => 2]);

    $this->actingAs($this->admin)
        ->put(($this->url)('automations.update', ['rule' => $this->rule]), ($this->payload)(['name' => 'Renamed', 'inbox_uuid' => null, 'cooldown_minutes' => 30]))
        ->assertRedirect(($this->url)('automations.edit', ['rule' => $this->rule]));

    expect($this->rule->fresh()->name)->toBe('Renamed')
        ->and($this->rule->fresh()->inbox_id)->toBeNull()
        ->and($this->rule->fresh()->cooldown_minutes)->toBe(30);

    $this->actingAs($this->admin)->patch(($this->url)('automations.toggle', ['rule' => $this->rule]))->assertRedirect();
    expect($this->rule->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->admin)->patchJson(($this->url)('automations.toggle', ['rule' => $this->rule]), ['is_active' => true])->assertOk()->assertJson(['is_active' => true]);
    expect($this->rule->fresh()->is_active)->toBeTrue();

    $this->actingAs($this->admin)->postJson(($this->url)('automations.reorder'), ['rules' => [$second->uuid, $this->rule->uuid]])->assertOk();
    expect($second->fresh()->position)->toBe(1)->and($this->rule->fresh()->position)->toBe(2);

    $this->actingAs($this->admin)->postJson(($this->url)('automations.reorder'), ['rules' => [AutomationRule::factory()->create()->uuid]])->assertUnprocessable();

    $this->actingAs($this->admin)->delete(($this->url)('automations.destroy', ['rule' => $this->rule]))->assertRedirect(($this->url)('automations.index'));
    expect(AutomationRule::query()->whereKey($this->rule->id)->exists())->toBeFalse()
        ->and(AutomationRule::withTrashed()->whereKey($this->rule->id)->exists())->toBeTrue();
});

test('the dry-run endpoint evaluates conditions against a conversation', function () {
    $conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'subject' => 'Invoice 42']);
    Message::factory()->create(['conversation_id' => $conversation->id, 'from' => ['name' => 'Ada', 'email' => 'ada@bigcorp.com']]);

    $this->actingAs($this->admin)
        ->postJson(($this->url)('automations.test', ['rule' => $this->rule]), ['conversation_uuid' => $conversation->uuid])
        ->assertOk()
        ->assertJsonPath('matched', true)
        ->assertJsonPath('would_run', true)
        ->assertJsonPath('conditions.0.field', 'subject')
        ->assertJsonPath('conditions.0.actual', 'Invoice 42')
        ->assertJsonPath('conditions.0.passed', true);

    $this->actingAs($this->admin)
        ->postJson(($this->url)('automations.test', ['rule' => $this->rule]), [
            'conversation_uuid' => $conversation->uuid,
            'match' => 'all',
            'conditions' => [
                ['field' => 'sender', 'operator' => 'equals', 'value' => '@bigcorp.com'],
                ['field' => 'tag', 'operator' => 'equals', 'value' => 'vip'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('matched', false)
        ->assertJsonPath('conditions.0.passed', true)
        ->assertJsonPath('conditions.1.passed', false);

    $this->actingAs($this->admin)
        ->postJson(($this->url)('automations.test', ['rule' => $this->rule]), ['conversation_uuid' => Conversation::factory()->create()->uuid])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['conversation_uuid']);
});

test('the runs endpoint returns paginated audit rows', function () {
    $conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id]);

    foreach (['matched', 'skipped', 'failed'] as $status) {
        AutomationRun::create(['automation_rule_id' => $this->rule->id, 'conversation_id' => $conversation->id, 'trigger' => 'conversation_received', 'status' => $status, 'details' => ['status' => $status]]);
    }

    $this->actingAs($this->admin)
        ->getJson(($this->url)('automations.runs', ['rule' => $this->rule, 'per_page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('data.0.conversation.uuid', $conversation->uuid);

    $this->actingAs($this->admin)
        ->getJson(($this->url)('automations.runs', ['rule' => $this->rule, 'status' => 'failed']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'failed');
});
