<?php

use App\Enums\InboxRole;
use App\Enums\OrganizationRole;
use App\Models\Inbox;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->admin = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->members()->attach($this->admin, ['role' => OrganizationRole::Admin->value]);

    $this->member = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->members()->attach($this->member, ['role' => OrganizationRole::Member->value]);
});

test('members only see the shared inboxes they have access to and cannot manage', function () {
    $visible = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Support']);
    $visible->users()->attach($this->member->id, ['role' => InboxRole::Agent->value]);
    Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Finance']);

    $this->actingAs($this->member)
        ->get(route('settings.inboxes.index', $this->organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/inboxes/Index')
            ->where('organization.slug', $this->organization->slug)
            ->where('canManage', false)
            ->has('inboxes', 1, fn (Assert $inbox) => $inbox
                ->where('uuid', $visible->uuid)
                ->where('can_manage', false)
                ->has('users', 1)
                ->etc())
            ->has('roles', 3)
            ->has('members', 2));
});

test('organization admins can create an inbox and become its first admin', function () {
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->admin)
        ->post(route('settings.inboxes.store', $this->organization), [
            'name' => 'Billing',
            'email_address' => 'billing@example.com',
            'color' => 'amber',
            'description' => 'Invoices and refunds',
            'team_ids' => [$team->id],
        ])
        ->assertRedirect(route('settings.inboxes.index', $this->organization));

    $inbox = Inbox::query()->where('name', 'Billing')->firstOrFail();

    expect($inbox->color)->toBe('amber')
        ->and($inbox->roleFor($this->admin))->toBe(InboxRole::Admin)
        ->and($inbox->teams()->where('teams.id', $team->id)->first()?->pivot->role)->toBe(InboxRole::Agent);
});

test('members cannot create inboxes', function () {
    $this->actingAs($this->member)
        ->post(route('settings.inboxes.store', $this->organization), ['name' => 'Nope'])
        ->assertForbidden();
});

test('inbox admins can replace the access list', function () {
    $inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $inbox->users()->attach($this->admin->id, ['role' => InboxRole::Admin->value]);
    $inbox->users()->attach($this->member->id, ['role' => InboxRole::Viewer->value]);
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->admin)
        ->put(route('settings.inboxes.access', [$this->organization, $inbox]), [
            'teams' => [['id' => $team->id, 'role' => 'agent']],
            'users' => [['id' => $this->admin->id, 'role' => 'admin']],
        ])
        ->assertRedirect();

    $inbox->refresh();

    expect($inbox->roleFor($this->member))->toBeNull()
        ->and($inbox->roleFor($this->admin))->toBe(InboxRole::Admin)
        ->and($inbox->teams()->pluck('teams.id')->all())->toBe([$team->id]);
});

test('someone without the inbox admin role cannot change access', function () {
    $inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $inbox->users()->attach($this->member->id, ['role' => InboxRole::Agent->value]);

    $this->actingAs($this->member)
        ->put(route('settings.inboxes.access', [$this->organization, $inbox]), ['users' => [['id' => $this->member->id, 'role' => 'admin']]])
        ->assertForbidden();
});
