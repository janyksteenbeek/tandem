<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'google_oauth_client_id' => 'client-id',
        'google_oauth_client_secret' => 'super-secret-1234',
        'outbound_mail_settings' => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'mailer', 'password' => 'keep-me', 'from_name' => 'Northwind'],
    ]);

    $this->admin = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->members()->attach($this->admin, ['role' => OrganizationRole::Admin->value]);

    $this->member = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->members()->attach($this->member, ['role' => OrganizationRole::Member->value]);
});

test('only organization admins can open the integrations page', function () {
    $this->actingAs($this->member)
        ->get(route('settings.integrations.edit', $this->organization))
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get(route('settings.integrations.edit', $this->organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/organizations/Integrations')
            ->where('organization.google_oauth_client_secret_set', true)
            ->where('organization.google_oauth_client_secret_hint', '••••••••1234')
            ->missing('organization.google_oauth_client_secret')
            ->where('organization.outbound.password_set', true)
            ->missing('organization.outbound.password')
            ->where('google.redirect_uri', route('mail.gmail.callback'))
            ->has('google.scopes')
            ->has('timezones'));
});

test('saving with a blank secret and password keeps the stored ones', function () {
    $this->actingAs($this->admin)
        ->patch(route('settings.integrations.update', $this->organization), [
            'timezone' => 'Europe/London',
            'google_oauth_client_id' => 'new-client-id',
            'google_oauth_client_secret' => '',
            'google_pubsub_topic' => 'projects/demo/topics/tandem',
            'business_hours' => [
                'timezone' => 'Europe/London',
                'days' => ['mon' => [['start' => '09:00', 'end' => '17:00']], 'sun' => []],
                'holidays' => ['2026-12-25'],
            ],
            'outbound' => ['host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'mailer', 'password' => '', 'from_name' => 'Northwind Labs'],
        ])
        ->assertRedirect();

    $this->organization->refresh();

    expect($this->organization->timezone)->toBe('Europe/London')
        ->and($this->organization->google_oauth_client_id)->toBe('new-client-id')
        ->and($this->organization->google_oauth_client_secret)->toBe('super-secret-1234')
        ->and($this->organization->google_pubsub_topic)->toBe('projects/demo/topics/tandem')
        ->and($this->organization->business_hours['days']['mon'][0]['end'])->toBe('17:00')
        ->and($this->organization->business_hours['holidays'])->toBe(['2026-12-25'])
        ->and($this->organization->outbound_mail_settings['password'])->toBe('keep-me')
        ->and($this->organization->outbound_mail_settings['port'])->toBe(465)
        ->and($this->organization->outbound_mail_settings['from_name'])->toBe('Northwind Labs');
});

test('the secret and outbound settings can be cleared explicitly', function () {
    $this->actingAs($this->admin)
        ->patch(route('settings.integrations.update', $this->organization), [
            'timezone' => 'Europe/Amsterdam',
            'clear_google_secret' => true,
            'clear_outbound' => true,
        ])
        ->assertRedirect();

    $this->organization->refresh();

    expect($this->organization->google_oauth_client_secret)->toBeNull()
        ->and($this->organization->outbound_mail_settings)->toBeNull();
});

test('a malformed pub/sub topic is rejected', function () {
    $this->actingAs($this->admin)
        ->from(route('settings.integrations.edit', $this->organization))
        ->patch(route('settings.integrations.update', $this->organization), ['timezone' => 'UTC', 'google_pubsub_topic' => 'tandem-gmail'])
        ->assertSessionHasErrors('google_pubsub_topic');
});

test('members cannot update integrations', function () {
    $this->actingAs($this->member)
        ->patch(route('settings.integrations.update', $this->organization), ['timezone' => 'UTC'])
        ->assertForbidden();
});
