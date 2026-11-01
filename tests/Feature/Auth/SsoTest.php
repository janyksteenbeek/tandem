<?php

use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config()->set('sso.providers.google', [
        'enabled' => true,
        'name' => 'Google',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect' => '/auth/google/callback',
        'hosted_domains' => null,
    ]);
    config()->set('sso.force', false);
    config()->set('sso.link_existing_accounts', true);
});

/**
 * @param  array<string, mixed>  $raw
 */
function googleUser(string $id = 'google-123', string $email = 'jane@example.com', ?string $name = 'Jane Doe', bool $verified = true, array $raw = []): SocialiteUser
{
    return (new SocialiteUser)
        ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified, ...$raw])
        ->map([
            'id' => $id,
            'nickname' => null,
            'name' => $name,
            'email' => $email,
            'avatar' => 'https://lh3.googleusercontent.com/a/photo',
        ]);
}

test('login screen shares the enabled providers', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('sso.forced', false)
            ->where('sso.providers', [['key' => 'google', 'name' => 'Google']]),
        );
});

test('providers without credentials are not offered', function () {
    config()->set('sso.providers.google.client_id', null);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('sso.providers', []));

    $this->get(route('sso.redirect', 'google'))->assertNotFound();
});

test('unknown providers are not found', function () {
    $this->get(route('sso.redirect', 'facebook'))->assertNotFound();
    $this->get(route('sso.callback', 'facebook'))->assertNotFound();
});

test('redirect sends the visitor to the provider', function () {
    Socialite::fake('google');

    $this->get(route('sso.redirect', 'google'))
        ->assertRedirect('https://socialite.fake/google/authorize');
});

test('a first sign-in registers a verified user with a personal organization', function () {
    Event::fake([Registered::class]);
    Socialite::fake('google', googleUser());

    $response = $this->get(route('sso.callback', 'google'));

    $this->assertAuthenticated();

    $user = User::where('email', 'jane@example.com')->firstOrFail();

    expect($user->name)->toBe('Jane Doe')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->personalOrganization())->not->toBeNull()
        ->and($user->ssoIdentities()->where('provider', 'google')->where('provider_id', 'google-123')->exists())->toBeTrue();

    $response->assertRedirect(route('mail.home', ['current_organization' => $user->personalOrganization()->slug]));
    Event::assertDispatched(Registered::class);
});

test('a first sign-in links to the existing user with the same verified email', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);
    Socialite::fake('google', googleUser());

    $this->get(route('sso.callback', 'google'));

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1)
        ->and($user->ssoIdentities()->count())->toBe(1);
});

test('an unverified provider email is not linked to an existing account', function () {
    User::factory()->create(['email' => 'jane@example.com']);
    Socialite::fake('google', googleUser(verified: false));

    $this->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    $this->assertGuest();
    expect(SsoIdentity::count())->toBe(0);
});

test('linking by email can be switched off', function () {
    config()->set('sso.link_existing_accounts', false);
    User::factory()->create(['email' => 'jane@example.com']);
    Socialite::fake('google', googleUser());

    $this->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    $this->assertGuest();
});

test('a linked identity signs in its user even after the email changed', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    SsoIdentity::factory()->for($user)->create(['provider_id' => 'google-123']);
    Socialite::fake('google', googleUser(email: 'new@example.com'));

    $this->get(route('sso.callback', 'google'));

    $this->assertAuthenticatedAs($user);
    expect($user->ssoIdentities()->first()->email)->toBe('new@example.com')
        ->and($user->fresh()->email)->toBe('old@example.com');
});

test('new accounts are refused when registration is closed', function () {
    config()->set('fortify.features', array_values(array_diff(config('fortify.features'), [Features::registration()])));
    Socialite::fake('google', googleUser());

    $this->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('google accounts outside the allowed workspace domains are refused', function () {
    config()->set('sso.providers.google.hosted_domains', 'example.com, Example.org');

    Socialite::fake('google', googleUser(raw: ['hd' => 'other.com']));
    $this->get(route('sso.callback', 'google'))->assertSessionHasErrors('sso');
    $this->assertGuest();

    Socialite::fake('google', googleUser());
    $this->get(route('sso.callback', 'google'))->assertSessionHasErrors('sso');
    $this->assertGuest();

    Socialite::fake('google', googleUser(raw: ['hd' => 'EXAMPLE.org']));
    $this->get(route('sso.callback', 'google'));
    $this->assertAuthenticated();
});

test('a denied authorisation returns to the login screen with an error', function () {
    Socialite::fake('google', googleUser());

    $this->get(route('sso.callback', ['provider' => 'google', 'error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    $this->assertGuest();
});

test('users with two factor enabled are sent to the challenge', function () {
    $user = User::factory()->withTwoFactor()->create();
    SsoIdentity::factory()->for($user)->create(['provider_id' => 'google-123']);
    Socialite::fake('google', googleUser(email: $user->email));

    $this->get(route('sso.callback', 'google'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

test('a signed-in user connects a provider from the security settings', function () {
    $user = User::factory()->create();
    Socialite::fake('google', googleUser(email: 'other@example.com'));

    $this->actingAs($user)->get(route('sso.redirect', 'google'));
    $this->actingAs($user)->get(route('sso.callback', 'google'))
        ->assertRedirect(route('security.edit'));

    expect($user->ssoIdentities()->where('provider_id', 'google-123')->exists())->toBeTrue();
});

test('an identity connected to another user cannot be linked', function () {
    $owner = User::factory()->create();
    SsoIdentity::factory()->for($owner)->create(['provider_id' => 'google-123']);
    $user = User::factory()->create();
    Socialite::fake('google', googleUser());

    $this->actingAs($user)->get(route('sso.redirect', 'google'));
    $this->actingAs($user)->get(route('sso.callback', 'google'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors('sso');

    expect($user->ssoIdentities()->count())->toBe(0);
});

test('re-authenticating with a connected provider confirms the password', function () {
    $user = User::factory()->create();
    SsoIdentity::factory()->for($user)->create(['provider_id' => 'google-123']);
    Socialite::fake('google', googleUser());

    $this->actingAs($user)->get(route('sso.redirect', ['provider' => 'google', 'intent' => 'confirm']));
    $this->actingAs($user)->get(route('sso.callback', 'google'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('auth.password_confirmed_at');
});

test('re-authenticating with an unconnected provider does not confirm the password', function () {
    $user = User::factory()->create();
    Socialite::fake('google', googleUser());

    $this->actingAs($user)->get(route('sso.redirect', ['provider' => 'google', 'intent' => 'confirm']));
    $this->actingAs($user)->get(route('sso.callback', 'google'))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('sso')
        ->assertSessionMissing('auth.password_confirmed_at');

    expect($user->ssoIdentities()->count())->toBe(0);
});

test('confirm password screen lists the connected providers', function () {
    $user = User::factory()->create();
    SsoIdentity::factory()->for($user)->create();

    $this->actingAs($user)->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/ConfirmPassword')
            ->where('ssoConnected', ['google']),
        );
});

test('security settings list the connected identities', function () {
    $user = User::factory()->create();
    SsoIdentity::factory()->for($user)->create(['email' => 'jane@example.com']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->where('canUpdatePassword', true)
            ->where('ssoIdentities.0.provider', 'google')
            ->where('ssoIdentities.0.email', 'jane@example.com'),
        );
});

test('a user disconnects a provider', function () {
    $user = User::factory()->create();
    SsoIdentity::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('sso.destroy', 'google'))->assertRedirect();

    expect($user->ssoIdentities()->count())->toBe(0);
});

test('disconnecting a provider that is not connected is not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('sso.destroy', 'google'))->assertNotFound();
});

describe('forced', function () {
    beforeEach(fn () => config()->set('sso.force', true));

    test('the login screen only offers the providers', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sso.forced', true));
    });

    test('forcing is ignored while no provider is enabled', function () {
        config()->set('sso.providers.google.enabled', false);
        $user = User::factory()->create();

        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('sso.forced', false));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticated();
    });

    test('password login is refused', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden();

        $this->assertGuest();
    });

    test('registration and password reset screens redirect to the login screen', function () {
        $this->get(route('register'))->assertRedirect(route('login'));
        $this->get(route('password.request'))->assertRedirect(route('login'));
        $this->get(route('password.reset', 'token'))->assertRedirect(route('login'));

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertForbidden();

        $this->post(route('password.email'), ['email' => 'test@example.com'])->assertForbidden();
        expect(User::count())->toBe(0);
    });

    test('passkey login is refused', function () {
        $this->getJson(route('passkey.login-options'))->assertForbidden();
    });

    test('password changes are refused and hidden', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertForbidden();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('canUpdatePassword', false));
    });

    test('signing in through a provider still works', function () {
        Socialite::fake('google', googleUser());

        $this->get(route('sso.callback', 'google'));

        $this->assertAuthenticated();
    });

    test('the last connected provider cannot be disconnected', function () {
        $user = User::factory()->create();
        SsoIdentity::factory()->for($user)->create();

        $this->actingAs($user)->delete(route('sso.destroy', 'google'))
            ->assertRedirect()
            ->assertSessionHasErrors('sso');

        expect($user->ssoIdentities()->count())->toBe(1);
    });
});
