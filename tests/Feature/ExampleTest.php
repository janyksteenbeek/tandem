<?php

use App\Models\User;

test('guests are sent from the root to the login form', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('signed-in users are sent from the root into their mail', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertRedirect(config('fortify.home'));
});
