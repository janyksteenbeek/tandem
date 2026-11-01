<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

test('guests are sent from the root to the login form', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('signed-in users are sent from the root into their mail', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))
        ->assertRedirect(route('mail.home', ['current_organization' => $user->personalOrganization()->slug]));
});

test('signed-in users follow the mail home of the organization they switched to', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);
    $user->switchOrganization($organization);

    $this->actingAs($user)->get(route('home'))
        ->assertRedirect(route('mail.home', ['current_organization' => $organization->slug]));
});
