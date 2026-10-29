<?php

use App\Enums\InboxRole;
use App\Enums\OrganizationRole;
use App\Jobs\Mail\PushMailboxChange;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Conversations\ConversationAssignedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Notification::fake();

    $this->organization = Organization::factory()->create();
    $this->agent = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->teammate = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->outsider = User::factory()->create();

    $this->organization->memberships()->create(['user_id' => $this->agent->id, 'role' => OrganizationRole::Member]);
    $this->organization->memberships()->create(['user_id' => $this->teammate->id, 'role' => OrganizationRole::Member]);

    $this->team = Team::factory()->create(['organization_id' => $this->organization->id]);
    $this->team->members()->attach([$this->agent->id, $this->teammate->id]);

    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $this->inbox->teams()->attach($this->team->id, ['role' => InboxRole::Agent->value]);

    $this->account = MailAccount::factory()->create(['inbox_id' => $this->inbox->id, 'organization_id' => $this->organization->id]);
    $this->conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'organization_id' => $this->organization->id, 'mail_account_id' => $this->account->id]);
    Message::factory()->create(['conversation_id' => $this->conversation->id, 'mail_account_id' => $this->account->id]);
});

test('an agent can assign a conversation to a teammate and the activity is recorded', function () {
    $this->actingAs($this->agent)
        ->postJson(route('conversations.assign', $this->conversation), ['assignee_id' => $this->teammate->id])
        ->assertOk()
        ->assertJsonPath('conversation.assignee.id', $this->teammate->id);

    $this->assertDatabaseHas('conversation_events', ['conversation_id' => $this->conversation->id, 'type' => 'assigned', 'actor_id' => $this->agent->id]);
    $this->assertDatabaseHas('conversation_followers', ['conversation_id' => $this->conversation->id, 'user_id' => $this->teammate->id]);

    Notification::assertSentTo($this->teammate, ConversationAssignedNotification::class);
});

test('a conversation cannot be assigned to someone without inbox access', function () {
    $this->organization->memberships()->create(['user_id' => $this->outsider->id, 'role' => OrganizationRole::Member]);

    $this->actingAs($this->agent)
        ->postJson(route('conversations.assign', $this->conversation), ['assignee_id' => $this->outsider->id])
        ->assertStatus(422);
});

test('users outside the inbox cannot act on its conversations', function () {
    $this->actingAs($this->outsider)
        ->postJson(route('conversations.status', $this->conversation), ['status' => 'closed'])
        ->assertForbidden();

    $this->actingAs($this->outsider)->getJson(route('conversations.show', $this->conversation))->assertForbidden();
});

test('viewers can read but not change a conversation', function () {
    $viewer = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->memberships()->create(['user_id' => $viewer->id, 'role' => OrganizationRole::Member]);
    $this->inbox->users()->attach($viewer->id, ['role' => InboxRole::Viewer->value]);

    $this->actingAs($viewer)->getJson(route('conversations.show', $this->conversation))->assertOk();
    $this->actingAs($viewer)->postJson(route('conversations.status', $this->conversation), ['status' => 'closed'])->assertForbidden();
});

test('closing and snoozing update status and timestamps', function () {
    $this->actingAs($this->agent)
        ->postJson(route('conversations.status', $this->conversation), ['status' => 'closed'])
        ->assertOk()
        ->assertJsonPath('conversation.status', 'closed');

    expect($this->conversation->fresh()->closed_at)->not->toBeNull();

    $until = now()->addDay()->toISOString();

    $this->actingAs($this->agent)
        ->postJson(route('conversations.status', $this->conversation), ['status' => 'snoozed', 'snoozed_until' => $until])
        ->assertOk()
        ->assertJsonPath('conversation.status', 'snoozed');

    expect($this->conversation->fresh()->snoozed_until)->not->toBeNull();
});

test('the snooze scheduler reopens elapsed conversations', function () {
    $this->conversation->forceFill(['status' => 'snoozed', 'snoozed_until' => now()->subMinute(), 'in_inbox' => false, 'is_archived' => true])->save();

    $this->artisan('conversations:wake-snoozed')->assertSuccessful();

    $fresh = $this->conversation->fresh();

    expect($fresh->status->value)->toBe('open')
        ->and($fresh->in_inbox)->toBeTrue()
        ->and($fresh->is_archived)->toBeFalse();
});

test('archiving mirrors the change to the provider', function () {
    $this->actingAs($this->agent)
        ->postJson(route('conversations.mailbox', $this->conversation), ['action' => 'archive'])
        ->assertOk()
        ->assertJsonPath('conversation.is_archived', true)
        ->assertJsonPath('conversation.in_inbox', false);

    Queue::assertPushed(PushMailboxChange::class, fn (PushMailboxChange $job) => $job->operation === 'archive' && $job->account->is($this->account));
});

test('tags can be created on the fly and must belong to the organization', function () {
    $this->actingAs($this->agent)
        ->postJson(route('conversations.tags', $this->conversation), ['name' => 'Escalated', 'attached' => true])
        ->assertOk()
        ->assertJsonPath('conversation.tags.0.name', 'Escalated');

    $this->assertDatabaseHas('tags', ['organization_id' => $this->organization->id, 'slug' => 'escalated']);

    $foreignTag = Tag::factory()->create();

    $this->actingAs($this->agent)
        ->postJson(route('conversations.tags', $this->conversation), ['tag_id' => $foreignTag->id, 'attached' => true])
        ->assertStatus(422);
});

test('marking unread flips the latest message and counters', function () {
    $this->conversation->messages()->update(['is_read' => true]);
    $this->conversation->refreshCounters();

    $this->actingAs($this->agent)
        ->postJson(route('conversations.read', $this->conversation), ['read' => false])
        ->assertOk()
        ->assertJsonPath('conversation.is_read', false)
        ->assertJsonPath('conversation.unread_count', 1);

    Queue::assertPushed(PushMailboxChange::class, fn (PushMailboxChange $job) => $job->operation === 'unread');
});

test('bulk actions only touch conversations the user may act on', function () {
    $other = Conversation::factory()->create();

    $this->actingAs($this->agent)
        ->postJson(route('conversations.bulk'), ['conversations' => [$this->conversation->uuid, $other->uuid], 'action' => 'status', 'status' => 'closed'])
        ->assertOk()
        ->assertJsonCount(1, 'conversations');

    expect($this->conversation->fresh()->status->value)->toBe('closed')
        ->and($other->fresh()->status->value)->toBe('open');
});
