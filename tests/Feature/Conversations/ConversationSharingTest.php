<?php

use App\Enums\InboxRole;
use App\Enums\OrganizationRole;
use App\Jobs\Mail\PushMailboxChange;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->organization = Organization::factory()->create();
    $this->owner = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->teammate = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->memberships()->create(['user_id' => $this->owner->id, 'role' => OrganizationRole::Member]);
    $this->organization->memberships()->create(['user_id' => $this->teammate->id, 'role' => OrganizationRole::Member]);

    $team = Team::factory()->create(['organization_id' => $this->organization->id]);
    $team->members()->attach([$this->owner->id, $this->teammate->id]);

    $this->shared = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $this->shared->teams()->attach($team->id, ['role' => InboxRole::Agent->value]);

    $this->personalAccount = MailAccount::factory()->personal($this->owner)->create();
    $this->conversation = Conversation::factory()->create(['inbox_id' => $this->personalAccount->inbox_id, 'organization_id' => null, 'mail_account_id' => $this->personalAccount->id]);
    Message::factory()->create(['conversation_id' => $this->conversation->id, 'mail_account_id' => $this->personalAccount->id]);
});

test('a personal conversation stays private until it is explicitly shared', function () {
    $this->actingAs($this->teammate)->getJson(route('conversations.show', $this->conversation))->assertForbidden();

    $this->actingAs($this->owner)
        ->postJson(route('conversations.share', $this->conversation), ['inbox' => $this->shared->uuid, 'mode' => 'share', 'note' => 'Can someone take this?'])
        ->assertOk()
        ->assertJsonPath('conversation.shares.0.inbox_uuid', $this->shared->uuid)
        ->assertJsonPath('conversation.inbox_uuid', $this->personalAccount->inbox->uuid);

    $this->actingAs($this->teammate)->getJson(route('conversations.show', $this->conversation))->assertOk();

    $this->assertDatabaseHas('conversation_events', ['conversation_id' => $this->conversation->id, 'type' => 'shared']);

    $this->actingAs($this->teammate)
        ->get(route('mail.inbox', ['current_organization' => $this->organization->slug, 'inbox' => $this->shared->uuid, 'view' => 'open']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('conversations.data', 1));
});

test('sharing never touches the provider mailbox', function () {
    $this->actingAs($this->owner)
        ->postJson(route('conversations.share', $this->conversation), ['inbox' => $this->shared->uuid, 'mode' => 'share'])
        ->assertOk();

    Queue::assertNotPushed(PushMailboxChange::class);
});

test('unsharing removes access immediately', function () {
    $this->actingAs($this->owner)->postJson(route('conversations.share', $this->conversation), ['inbox' => $this->shared->uuid, 'mode' => 'share'])->assertOk();
    $this->actingAs($this->teammate)->getJson(route('conversations.show', $this->conversation))->assertOk();

    $this->actingAs($this->owner)->deleteJson(route('conversations.unshare', [$this->conversation, $this->shared]))->assertOk();
    $this->actingAs($this->teammate)->getJson(route('conversations.show', $this->conversation))->assertForbidden();
});

test('transferring moves ownership to the team inbox and keeps a link for the original owner', function () {
    $this->actingAs($this->owner)
        ->postJson(route('conversations.share', $this->conversation), ['inbox' => $this->shared->uuid, 'mode' => 'transfer'])
        ->assertOk()
        ->assertJsonPath('conversation.inbox_uuid', $this->shared->uuid)
        ->assertJsonPath('conversation.origin_inbox.uuid', $this->personalAccount->inbox->uuid);

    $fresh = $this->conversation->fresh();

    expect($fresh->organization_id)->toBe($this->organization->id)
        ->and($fresh->shares()->where('inbox_id', $this->personalAccount->inbox_id)->exists())->toBeTrue();

    $this->actingAs($this->teammate)->postJson(route('conversations.status', $fresh), ['status' => 'closed'])->assertOk();
    $this->assertDatabaseHas('conversation_events', ['conversation_id' => $fresh->id, 'type' => 'moved']);
});

test('only people who can see both inboxes may share into a target', function () {
    $foreignInbox = Inbox::factory()->create();

    $this->actingAs($this->owner)
        ->postJson(route('conversations.share', $this->conversation), ['inbox' => $foreignInbox->uuid, 'mode' => 'share'])
        ->assertForbidden();

    $this->actingAs($this->teammate)
        ->postJson(route('conversations.share', $this->conversation), ['inbox' => $this->shared->uuid, 'mode' => 'share'])
        ->assertForbidden();
});
