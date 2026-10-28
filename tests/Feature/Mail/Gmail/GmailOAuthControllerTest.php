<?php

use App\Enums\InboxRole;
use App\Enums\InboxType;
use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Enums\SyncRunType;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\Providers\Gmail\GmailOAuth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeGmailApi;

function afterConnectUrl(): string
{
    return Route::has('mail.accounts.index') ? route('mail.accounts.index') : url('/');
}

beforeEach(function () {
    config()->set('services.google.client_id', 'global-client-id');
    config()->set('services.google.client_secret', 'global-secret');
    config()->set('services.google.pubsub_topic', null);
});

it('redirects to Google with offline access and remembers the consent state in the session', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('mail.gmail.redirect'));

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    $query = [];
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    $pending = session(GmailOAuth::SESSION_KEY);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($query['client_id'])->toBe('global-client-id')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent')
        ->and($query['include_granted_scopes'])->toBe('true')
        ->and($query['redirect_uri'])->toBe(route('mail.gmail.callback'))
        ->and($query['scope'])->toContain('https://www.googleapis.com/auth/gmail.modify')
        ->and($query['state'])->toBe($pending['state'])
        ->and($pending['user_id'])->toBe($user->id)
        ->and($pending['inbox_uuid'])->toBeNull()
        ->and($pending['organization_id'])->toBe($user->current_organization_id);
});

it('uses the organization OAuth client when connecting to a shared inbox as its admin', function () {
    $user = User::factory()->create();
    $organization = $user->currentOrganization;
    $organization->forceFill(['google_oauth_client_id' => 'org-client-id', 'google_oauth_client_secret' => 'org-secret'])->save();
    $inbox = Inbox::factory()->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->get(route('mail.gmail.redirect', ['inbox' => $inbox->uuid]));

    $query = [];
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('org-client-id')
        ->and(session(GmailOAuth::SESSION_KEY)['inbox_uuid'])->toBe($inbox->uuid);
});

it('forbids connecting a shared inbox the user does not administer', function () {
    $user = User::factory()->create();
    $inbox = Inbox::factory()->create();
    $inbox->users()->attach($user, ['role' => InboxRole::Agent->value]);

    $this->actingAs($user)->get(route('mail.gmail.redirect', ['inbox' => $inbox->uuid]))->assertForbidden();
});

it('creates a personal inbox, mail account and sender identity after a successful callback', function () {
    Queue::fake([SyncMailAccount::class]);

    $user = User::factory()->create();

    $api = FakeGmailApi::install()
        ->json(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/gmail.modify', 'token_type' => 'Bearer'])
        ->json(['id' => '10001', 'email' => 'Ada@Gmail.com', 'verified_email' => true, 'name' => 'Ada Lovelace']);

    $response = $this->actingAs($user)
        ->withSession([GmailOAuth::SESSION_KEY => [
            'state' => 'nonce-123',
            'organization_id' => $user->current_organization_id,
            'user_id' => $user->id,
            'inbox_uuid' => null,
            'created_at' => now()->getTimestamp(),
        ]])
        ->get(route('mail.gmail.callback', ['state' => 'nonce-123', 'code' => 'auth-code']));

    $response->assertRedirect();

    $account = MailAccount::query()->where('email_address', 'ada@gmail.com')->firstOrFail();
    $inbox = $account->inbox;

    expect($account->provider)->toBe(MailProvider::Gmail)
        ->and($account->status)->toBe(MailAccountStatus::Pending)
        ->and($account->user_id)->toBe($user->id)
        ->and($account->organization_id)->toBeNull()
        ->and($account->display_name)->toBe('Ada Lovelace')
        ->and($account->credential('access_token'))->toBe('access-1')
        ->and($account->credential('refresh_token'))->toBe('refresh-1')
        ->and($account->credential('oauth_organization_id'))->toBe($user->current_organization_id)
        ->and($account->credential('expires_at'))->toBeGreaterThan(now()->getTimestamp())
        ->and($inbox->type)->toBe(InboxType::Personal)
        ->and($inbox->user_id)->toBe($user->id)
        ->and($inbox->organization_id)->toBeNull()
        ->and($inbox->name)->toBe('ada@gmail.com')
        ->and($inbox->email_address)->toBe('ada@gmail.com');

    $this->assertDatabaseHas('sender_identities', ['mail_account_id' => $account->id, 'email' => 'ada@gmail.com', 'name' => 'Ada Lovelace', 'is_default' => true]);

    expect(session(GmailOAuth::SESSION_KEY))->toBeNull()
        ->and((string) $api->request(0)->getUri())->toBe('https://oauth2.googleapis.com/token')
        ->and((string) $api->request(0)->getBody())->toContain('code=auth-code', 'grant_type=authorization_code')
        ->and((string) $api->request(1)->getUri())->toContain('/oauth2/v2/userinfo');

    Queue::assertPushed(SyncMailAccount::class, fn (SyncMailAccount $job) => $job->account->is($account) && $job->type === SyncRunType::Initial);
});

it('keeps the existing refresh token when Google does not return a new one on reconnect', function () {
    Queue::fake([SyncMailAccount::class]);

    $user = User::factory()->create();
    $existing = MailAccount::factory()->gmail()->personal($user)->needsReauth()->create([
        'email_address' => 'ada@gmail.com',
        'credentials' => ['access_token' => 'old', 'refresh_token' => 'keep-me', 'expires_at' => 1],
    ]);

    FakeGmailApi::install()
        ->json(['access_token' => 'access-2', 'expires_in' => 3599, 'token_type' => 'Bearer'])
        ->json(['id' => '10001', 'email' => 'ada@gmail.com', 'name' => 'Ada Lovelace']);

    $this->actingAs($user)
        ->withSession([GmailOAuth::SESSION_KEY => ['state' => 'n', 'organization_id' => $user->current_organization_id, 'user_id' => $user->id, 'inbox_uuid' => null, 'created_at' => now()->getTimestamp()]])
        ->get(route('mail.gmail.callback', ['state' => 'n', 'code' => 'auth-code']))
        ->assertRedirect();

    $existing->refresh();

    expect(MailAccount::count())->toBe(1)
        ->and(Inbox::count())->toBe(1)
        ->and($existing->status)->toBe(MailAccountStatus::Pending)
        ->and($existing->last_error)->toBeNull()
        ->and($existing->credential('access_token'))->toBe('access-2')
        ->and($existing->credential('refresh_token'))->toBe('keep-me');

    Queue::assertPushed(SyncMailAccount::class, fn (SyncMailAccount $job) => $job->type === SyncRunType::Incremental);
});

it('rejects a callback whose state does not match the session', function () {
    Queue::fake([SyncMailAccount::class]);

    $user = User::factory()->create();
    $api = FakeGmailApi::install();

    $this->actingAs($user)
        ->withSession([GmailOAuth::SESSION_KEY => ['state' => 'expected', 'organization_id' => null, 'user_id' => $user->id, 'created_at' => now()->getTimestamp()]])
        ->get(route('mail.gmail.callback', ['state' => 'tampered', 'code' => 'auth-code']))
        ->assertRedirect(afterConnectUrl());

    expect(MailAccount::count())->toBe(0)
        ->and($api->requests)->toBeEmpty();

    Queue::assertNothingPushed();
});

it('reports a denied consent screen without creating anything', function () {
    Queue::fake([SyncMailAccount::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([GmailOAuth::SESSION_KEY => ['state' => 'n', 'organization_id' => null, 'user_id' => $user->id, 'created_at' => now()->getTimestamp()]])
        ->get(route('mail.gmail.callback', ['error' => 'access_denied']))
        ->assertRedirect(afterConnectUrl());

    expect(MailAccount::count())->toBe(0)
        ->and(session(GmailOAuth::SESSION_KEY))->toBeNull();

    Queue::assertNothingPushed();
});
