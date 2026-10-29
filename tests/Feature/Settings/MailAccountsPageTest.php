<?php

use App\Enums\InboxRole;
use App\Enums\OrganizationRole;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->members()->attach($this->user, ['role' => OrganizationRole::Member->value]);
    $this->user->switchOrganization($this->organization);
});

test('the mail accounts page lists personal accounts and only the shared inboxes the user administers', function () {
    $personal = MailAccount::factory()->personal($this->user)->create();

    $administered = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Support']);
    $administered->users()->attach($this->user->id, ['role' => InboxRole::Admin->value]);
    MailAccount::factory()->forwarding()->create(['inbox_id' => $administered->id]);

    $agentOnly = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Sales']);
    $agentOnly->users()->attach($this->user->id, ['role' => InboxRole::Agent->value]);

    $this->actingAs($this->user)
        ->get(route('mail.accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/MailAccounts')
            ->has('personalAccounts', 1, fn (Assert $account) => $account
                ->where('uuid', $personal->uuid)
                ->where('provider', 'imap')
                ->where('healthy', true)
                ->where('inbox.type', 'personal')
                ->missing('settings.password')
                ->etc())
            ->has('sharedInboxes', 1, fn (Assert $inbox) => $inbox
                ->where('uuid', $administered->uuid)
                ->has('accounts', 1, fn (Assert $account) => $account
                    ->where('provider', 'forwarding')
                    ->where('inbound_token_set', true)
                    ->etc())
                ->etc())
            ->where('forwardingDomain', config('tandem.forwarding.domain'))
            ->where('googleConfigured', false));
});

test('google counts as configured once the organization stores its own oauth client', function () {
    $this->organization->forceFill(['google_oauth_client_id' => 'client-id', 'google_oauth_client_secret' => 'secret'])->save();

    $this->actingAs($this->user)
        ->get(route('mail.accounts.index'))
        ->assertInertia(fn (Assert $page) => $page->where('googleConfigured', true));
});

test('guests are sent to the login page', function () {
    $this->get(route('mail.accounts.index'))->assertRedirect(route('login'));
});
