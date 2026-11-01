<?php

use App\Enums\OrganizationRole;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->memberships()->create(['user_id' => $this->user->id, 'role' => OrganizationRole::Member]);
});

test('a mailto link opens the default inbox with the composer prefilled', function () {
    $account = MailAccount::factory()->personal($this->user)->create();
    $slug = $this->user->fresh()->currentOrganization->slug;

    $this->actingAs($this->user)
        ->get(route('mail.compose', ['mailto' => 'mailto:ann@example.com?subject=Hello&body=Hi%20there']))
        ->assertRedirect(route('mail.inbox', ['current_organization' => $slug, 'inbox' => $account->inbox->uuid, 'view' => 'inbox']))
        ->assertInertiaFlash('compose.to.0.email', 'ann@example.com')
        ->assertInertiaFlash('compose.subject', 'Hello')
        ->assertInertiaFlash('compose.html_body', '<p>Hi there</p>');
});

test('the flash survives the redirect and reaches the mail page', function () {
    MailAccount::factory()->personal($this->user)->create();

    $this->actingAs($this->user)
        ->followingRedirects()
        ->get(route('mail.compose', ['to' => 'ann@example.com', 'subject' => 'Plain params']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('mail/Inbox')
            ->hasFlash('compose.to.0.email', 'ann@example.com')
            ->hasFlash('compose.subject', 'Plain params'));
});

test('an empty or malformed link still opens a blank composer', function () {
    MailAccount::factory()->personal($this->user)->create();

    $this->actingAs($this->user)
        ->get(route('mail.compose', ['mailto' => 'javascript:alert(1)']))
        ->assertRedirect()
        ->assertInertiaFlash('compose.to', [])
        ->assertInertiaFlash('compose.subject', null);
});

test('guests are sent to log in first', function () {
    $this->get(route('mail.compose', ['mailto' => 'mailto:ann@example.com']))
        ->assertRedirect(route('login'));
});

test('without an inbox the user is sent to connect one', function () {
    $this->actingAs($this->user)
        ->get(route('mail.compose', ['mailto' => 'mailto:ann@example.com']))
        ->assertRedirect(route('mail.accounts.index'));
});
