<?php

use App\Enums\OrganizationRole;
use App\Enums\TeamRole;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->admin = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->members()->attach($this->admin, ['role' => OrganizationRole::Owner->value]);

    $this->member = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->members()->attach($this->member, ['role' => OrganizationRole::Member->value]);
});

test('the teams page renders every team with its members and roles', function () {
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);
    $team->members()->attach($this->admin, ['role' => TeamRole::Lead->value]);
    $team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->actingAs($this->member)
        ->get(route('settings.teams.index', $this->organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/teams/Index')
            ->where('canManage', false)
            ->has('teams', 1, fn (Assert $row) => $row
                ->where('slug', $team->slug)
                ->has('members', 2)
                ->where('members.0.role', fn ($role) => in_array($role, ['lead', 'member'], true))
                ->etc())
            ->has('members', 2));

    $this->actingAs($this->admin)
        ->get(route('settings.teams.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true));
});

test('admins can create a team with leads and members', function () {
    $this->actingAs($this->admin)
        ->post(route('settings.teams.store', $this->organization), [
            'name' => 'Support',
            'color' => 'teal',
            'description' => 'First line',
            'members' => [
                ['id' => $this->admin->id, 'role' => 'lead'],
                ['id' => $this->member->id, 'role' => 'member'],
            ],
        ])
        ->assertRedirect();

    $team = Team::query()->where('name', 'Support')->firstOrFail();

    expect($team->members()->count())->toBe(2)
        ->and($team->members()->where('users.id', $this->admin->id)->first()?->membership->role)->toBe(TeamRole::Lead);
});

test('members cannot create or delete teams', function () {
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->member)
        ->post(route('settings.teams.store', $this->organization), ['name' => 'Nope'])
        ->assertForbidden();

    $this->actingAs($this->member)
        ->delete(route('settings.teams.destroy', [$this->organization, $team]))
        ->assertForbidden();

    expect(Team::query()->whereKey($team->id)->exists())->toBeTrue();
});

test('updating a team replaces its member list', function () {
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);
    $team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->actingAs($this->admin)
        ->patch(route('settings.teams.update', [$this->organization, $team]), [
            'name' => 'Renamed',
            'members' => [['id' => $this->admin->id, 'role' => 'lead']],
        ])
        ->assertRedirect();

    $team->refresh();

    expect($team->name)->toBe('Renamed')
        ->and($team->members()->pluck('users.id')->all())->toBe([$this->admin->id]);
});
