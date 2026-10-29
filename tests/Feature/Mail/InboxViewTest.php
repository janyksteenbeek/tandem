<?php

use App\Enums\InboxRole;
use App\Enums\OrganizationRole;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->memberships()->create(['user_id' => $this->user->id, 'role' => OrganizationRole::Member]);
});

test('a personal inbox renders its conversations', function () {
    $account = MailAccount::factory()->personal($this->user)->create();
    Conversation::factory()->count(3)->create(['inbox_id' => $account->inbox_id, 'organization_id' => null]);

    $this->actingAs($this->user)
        ->get(route('mail.inbox', ['current_organization' => $this->organization->slug, 'inbox' => $account->inbox->uuid, 'view' => 'inbox']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('mail/Inbox')
            ->where('view', 'inbox')
            ->where('inbox.uuid', $account->inbox->uuid)
            ->has('conversations.data', 3));
});

test('the mail home redirects to the default inbox', function () {
    $account = MailAccount::factory()->personal($this->user)->create();

    $this->actingAs($this->user)
        ->get(route('mail.home', ['current_organization' => $this->organization->slug]))
        ->assertRedirect(route('mail.inbox', ['current_organization' => $this->organization->slug, 'inbox' => $account->inbox->uuid, 'view' => 'inbox']));
});

test('a shared inbox is only visible to people with access', function () {
    $inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $conversation = Conversation::factory()->create(['inbox_id' => $inbox->id, 'organization_id' => $this->organization->id]);

    $this->actingAs($this->user)
        ->get(route('mail.inbox', ['current_organization' => $this->organization->slug, 'inbox' => $inbox->uuid, 'view' => 'open']))
        ->assertForbidden();

    $inbox->users()->attach($this->user->id, ['role' => InboxRole::Agent->value]);

    $this->actingAs($this->user)
        ->get(route('mail.inbox', ['current_organization' => $this->organization->slug, 'inbox' => $inbox->uuid, 'view' => 'open', 'conversation' => $conversation->uuid]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('mail/Inbox')
            ->where('conversation.uuid', $conversation->uuid)
            ->where('conversation.can.act', true));
});

test('a conversation from another organization cannot be opened through an accessible inbox', function () {
    $inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $inbox->users()->attach($this->user->id, ['role' => InboxRole::Agent->value]);

    $foreign = Conversation::factory()->create();

    $this->actingAs($this->user)
        ->get(route('mail.inbox', ['current_organization' => $this->organization->slug, 'inbox' => $inbox->uuid, 'view' => 'open', 'conversation' => $foreign->uuid]))
        ->assertForbidden();
});

test('personal inboxes are never visible to organization administrators', function () {
    $admin = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->memberships()->create(['user_id' => $admin->id, 'role' => OrganizationRole::Owner]);

    $account = MailAccount::factory()->personal($this->user)->create();
    $conversation = Conversation::factory()->create(['inbox_id' => $account->inbox_id, 'organization_id' => null]);

    $this->actingAs($admin)
        ->get(route('mail.inbox', ['current_organization' => $this->organization->slug, 'inbox' => $account->inbox->uuid, 'view' => 'inbox']))
        ->assertForbidden();

    $this->actingAs($admin)->getJson(route('conversations.show', $conversation))->assertForbidden();
});
