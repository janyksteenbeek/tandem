<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the old dashboard url redirects to mail', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/'.$user->currentOrganization->slug.'/dashboard')
        ->assertRedirect(route('mail.home', ['current_organization' => $user->currentOrganization->slug]));
});

test('shared props include pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->create(['name' => 'Laravel Organization']);

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('profile.edit'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.organization.name', 'Laravel Organization')
        ->where('pendingInvitations.0.organization.slug', $organization->slug)
        ->missing('pendingInvitations.0.organizationName'),
    );
});

test('shared props do not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    OrganizationInvitation::factory()->accepted()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('profile.edit'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('pendingInvitations', 0),
    );
});

test('shared props exclude expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->expired()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('profile.edit'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('organization_invitations', [
        'id' => $invitation->id,
    ]);
});

test('shared props do not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->expired()->create([
        'organization_id' => $organization->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('profile.edit'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('organization_invitations', [
        'id' => $invitation->id,
    ]);
});
