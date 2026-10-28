<?php

use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\OrganizationRole;
use App\Enums\TeamRole;
use App\Models\AutomationRule;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AutomationNotification;
use App\Services\Automations\ActionExecutor;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->owner = User::factory()->create(['name' => 'Olivia Owner']);
    $this->agent = User::factory()->create(['name' => 'Alex Agent']);
    $this->organization->members()->attach($this->owner, ['role' => OrganizationRole::Owner->value]);
    $this->organization->members()->attach($this->agent, ['role' => OrganizationRole::Member->value]);

    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Support']);
    $this->conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'subject' => 'Hello there']);
    Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'from' => ['name' => 'Ada Lovelace', 'email' => 'ada@customer.example'],
    ]);

    $this->rule = AutomationRule::factory()->create(['organization_id' => $this->organization->id, 'created_by_id' => $this->owner->id, 'name' => 'Test rule']);
    $this->executor = app(ActionExecutor::class);
    $this->execute = fn (string $type, array $params = []) => $this->executor->execute($this->rule, ['type' => $type, 'params' => $params], $this->conversation);
});

test('assign_user assigns an organization member and records an event', function () {
    $result = ($this->execute)('assign_user', ['user_id' => $this->agent->id]);

    expect($result['status'])->toBe('done')
        ->and($this->conversation->fresh()->assignee_id)->toBe($this->agent->id);

    $this->assertDatabaseHas('conversation_events', ['conversation_id' => $this->conversation->id, 'type' => 'assigned', 'actor_id' => null]);

    expect(($this->execute)('assign_user', ['user_id' => $this->agent->id])['reason'])->toBe('already_assigned');

    $outsider = User::factory()->create();
    expect(($this->execute)('assign_user', ['user_id' => $outsider->id])['reason'])->toBe('user_not_found');
});

test('assign_team round robin cycles through members and least busy picks the idle one', function () {
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);
    $team->members()->attach($this->owner, ['role' => TeamRole::Lead->value]);
    $team->members()->attach($this->agent, ['role' => TeamRole::Member->value]);

    $second = Conversation::factory()->create(['inbox_id' => $this->inbox->id]);
    $third = Conversation::factory()->create(['inbox_id' => $this->inbox->id]);

    $first = $this->executor->execute($this->rule, ['type' => 'assign_team', 'params' => ['team_id' => $team->id, 'strategy' => 'round_robin']], $this->conversation);
    $next = $this->executor->execute($this->rule, ['type' => 'assign_team', 'params' => ['team_id' => $team->id, 'strategy' => 'round_robin']], $second);
    $again = $this->executor->execute($this->rule, ['type' => 'assign_team', 'params' => ['team_id' => $team->id, 'strategy' => 'round_robin']], $third);

    expect($first['user_id'])->toBe($this->owner->id)
        ->and($next['user_id'])->toBe($this->agent->id)
        ->and($again['user_id'])->toBe($this->owner->id);

    $fourth = Conversation::factory()->create(['inbox_id' => $this->inbox->id]);
    $leastBusy = $this->executor->execute($this->rule, ['type' => 'assign_team', 'params' => ['team_id' => $team->id, 'strategy' => 'least_busy']], $fourth);

    expect($leastBusy['user_id'])->toBe($this->agent->id)
        ->and($leastBusy['strategy'])->toBe('least_busy');

    $empty = Team::factory()->create(['organization_id' => $this->organization->id]);
    expect(($this->execute)('assign_team', ['team_id' => $empty->id, 'strategy' => 'round_robin'])['reason'])->toBe('team_has_no_members');
});

test('tags can be added by id or name and removed again', function () {
    $tag = Tag::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Billing']);

    expect(($this->execute)('add_tag', ['tag_id' => $tag->id])['status'])->toBe('done')
        ->and($this->conversation->tags()->pluck('slug')->all())->toBe(['billing'])
        ->and(($this->execute)('add_tag', ['tag_id' => $tag->id])['reason'])->toBe('already_tagged');

    $byName = ($this->execute)('add_tag', ['tag_name' => 'VIP Customer']);

    expect($byName['status'])->toBe('done')
        ->and(Tag::query()->where('organization_id', $this->organization->id)->where('slug', 'vip-customer')->exists())->toBeTrue()
        ->and($this->conversation->tags()->count())->toBe(2);

    expect(($this->execute)('add_tag', ['tag_name' => 'vip customer'])['reason'])->toBe('already_tagged')
        ->and(Tag::query()->where('organization_id', $this->organization->id)->count())->toBe(2);

    expect(($this->execute)('remove_tag', ['tag_id' => $tag->id])['status'])->toBe('done')
        ->and($this->conversation->tags()->pluck('slug')->all())->toBe(['vip-customer'])
        ->and(($this->execute)('remove_tag', ['tag_id' => $tag->id])['reason'])->toBe('not_tagged')
        ->and(($this->execute)('remove_tag', ['tag_name' => 'does-not-exist'])['reason'])->toBe('tag_not_found');

    $this->assertDatabaseHas('conversation_events', ['conversation_id' => $this->conversation->id, 'type' => 'tag_removed']);
});

test('set_priority and set_status update the conversation', function () {
    expect(($this->execute)('set_priority', ['priority' => 'urgent'])['status'])->toBe('done')
        ->and($this->conversation->fresh()->priority)->toBe(ConversationPriority::Urgent)
        ->and(($this->execute)('set_priority', ['priority' => 'urgent'])['reason'])->toBe('unchanged');

    expect(($this->execute)('set_status', ['status' => 'closed'])['status'])->toBe('done')
        ->and($this->conversation->fresh()->status)->toBe(ConversationStatus::Closed)
        ->and($this->conversation->fresh()->closed_at)->not->toBeNull()
        ->and(($this->execute)('set_status', ['status' => 'snoozed'])['reason'])->toBe('invalid_status');

    expect(($this->execute)('set_status', ['status' => 'open'])['status'])->toBe('done')
        ->and($this->conversation->fresh()->closed_at)->toBeNull();

    $this->assertDatabaseHas('conversation_events', ['conversation_id' => $this->conversation->id, 'type' => 'priority_changed']);
    $this->assertDatabaseHas('conversation_events', ['conversation_id' => $this->conversation->id, 'type' => 'status_changed']);
});

test('move_to_inbox moves within the organization and keeps the origin', function () {
    $finance = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Finance']);
    $foreign = Inbox::factory()->create();

    expect(($this->execute)('move_to_inbox', ['inbox_uuid' => $foreign->uuid])['reason'])->toBe('inbox_not_found');

    $result = ($this->execute)('move_to_inbox', ['inbox_uuid' => $finance->uuid]);
    $fresh = $this->conversation->fresh();

    expect($result['status'])->toBe('done')
        ->and($fresh->inbox_id)->toBe($finance->id)
        ->and($fresh->origin_inbox_id)->toBe($this->inbox->id)
        ->and(($this->execute)('move_to_inbox', ['inbox_uuid' => $finance->uuid])['reason'])->toBe('already_in_inbox');

    $this->assertDatabaseHas('conversation_events', ['conversation_id' => $this->conversation->id, 'type' => 'moved']);
});

test('add_follower and add_comment attach collaborators and render variables', function () {
    expect(($this->execute)('add_follower', ['user_id' => $this->agent->id])['status'])->toBe('done')
        ->and($this->conversation->followers()->pluck('users.id')->all())->toBe([$this->agent->id])
        ->and(($this->execute)('add_follower', ['user_id' => $this->agent->id])['reason'])->toBe('already_following');

    $result = ($this->execute)('add_comment', ['body' => 'Flagged {{ contact.email }} on "{{ conversation.subject }}" by {{ unknown.var }}.']);
    $comment = $this->conversation->comments()->first();

    expect($result['status'])->toBe('done')
        ->and($comment->body)->toBe('Flagged ada@customer.example on "Hello there" by .')
        ->and($comment->user_id)->toBe($this->owner->id)
        ->and($this->conversation->fresh()->comment_count)->toBe(1)
        ->and(($this->execute)('add_comment', ['body' => '   '])['reason'])->toBe('empty_body');
});

test('notify_users stores database notifications for organization members only', function () {
    $outsider = User::factory()->create();

    $result = ($this->execute)('notify_users', ['user_ids' => [$this->agent->id, $outsider->id], 'message' => 'Heads up']);

    expect($result['status'])->toBe('done')
        ->and($result['user_ids'])->toBe([$this->agent->id])
        ->and($this->agent->notifications()->count())->toBe(1)
        ->and($outsider->notifications()->count())->toBe(0);

    $notification = $this->agent->notifications()->first();

    expect($notification->type)->toBe(AutomationNotification::class)
        ->and($notification->data['message'])->toBe('Heads up')
        ->and($notification->data['conversation_uuid'])->toBe($this->conversation->uuid)
        ->and($notification->data['rule_name'])->toBe('Test rule');

    expect(($this->execute)('notify_users', ['user_ids' => []])['reason'])->toBe('no_recipients');
});

test('unknown action types throw so the engine can record a failure', function () {
    ($this->execute)('explode');
})->throws(InvalidArgumentException::class);
