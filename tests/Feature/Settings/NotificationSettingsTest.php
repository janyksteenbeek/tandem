<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('webpush.vapid.public_key', 'BPublicKey');
    $this->user = User::factory()->create();
});

test('the notifications page shows the key, the defaults and the device count', function () {
    $this->user->updatePushSubscription('https://push.example/one', 'p256dh', 'auth');

    $this->actingAs($this->user)
        ->get(route('notification-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Notifications')
            ->where('publicKey', 'BPublicKey')
            ->where('topics', ['direct' => true, 'follows' => true, 'shared' => false])
            ->where('deviceCount', 1));
});

test('topics can be switched and unknown keys are ignored', function () {
    $this->actingAs($this->user)
        ->from(route('notification-settings.edit'))
        ->patch(route('notification-settings.update'), [
            'topics' => ['direct' => false, 'shared' => true, 'bogus' => true],
        ])
        ->assertRedirect(route('notification-settings.edit'));

    expect($this->user->fresh()->pushPreferences())
        ->toBe(['direct' => false, 'follows' => true, 'shared' => true]);
});

test('a device subscription can be stored, refreshed and removed', function () {
    $payload = [
        'endpoint' => 'https://push.example/device-1',
        'keys' => ['p256dh' => 'key', 'auth' => 'token'],
        'content_encoding' => 'aes128gcm',
    ];

    $this->actingAs($this->user)->postJson(route('push-subscriptions.store'), $payload)->assertOk();
    $this->actingAs($this->user)->postJson(route('push-subscriptions.store'), $payload)->assertOk();

    expect($this->user->pushSubscriptions()->count())->toBe(1);

    $this->actingAs($this->user)
        ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $payload['endpoint']])
        ->assertOk();

    expect($this->user->pushSubscriptions()->count())->toBe(0);
});

test('a subscription needs an https endpoint and both keys', function () {
    $this->actingAs($this->user)
        ->postJson(route('push-subscriptions.store'), [
            'endpoint' => 'http://push.example/device-1',
            'keys' => ['p256dh' => 'key'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint', 'keys.auth']);
});

test('guests cannot touch subscriptions', function () {
    $this->postJson(route('push-subscriptions.store'), [])->assertUnauthorized();
    $this->get(route('notification-settings.edit'))->assertRedirect(route('login'));
});
