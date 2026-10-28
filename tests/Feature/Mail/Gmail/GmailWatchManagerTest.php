<?php

use App\Enums\MailAccountStatus;
use App\Models\MailAccount;
use App\Services\Mail\Providers\Gmail\GmailWatchManager;
use Tests\Support\FakeGmailApi;

beforeEach(function () {
    config()->set('services.google.pubsub_topic', 'projects/tandem-test/topics/gmail-push');
});

it('registers a watch and stores its expiry', function () {
    $this->freezeTime();

    $account = MailAccount::factory()->gmail()->create(['watch_expires_at' => null]);
    $expiresAt = now()->addDays(7);

    $api = FakeGmailApi::install()->json(['historyId' => '4321', 'expiration' => (string) $expiresAt->getTimestampMs()]);

    app(GmailWatchManager::class)->watch($account);

    $account->refresh();

    expect($account->watch_expires_at?->getTimestamp())->toBe($expiresAt->getTimestamp())
        ->and($account->syncState('history_id'))->toBe('1000')
        ->and((string) $api->request(0)->getUri())->toContain('/gmail/v1/users/me/watch')
        ->and($api->requestJson(0))->toBe(['topicName' => 'projects/tandem-test/topics/gmail-push']);
});

it('prefers the organization Pub/Sub topic over the global one', function () {
    $account = MailAccount::factory()->gmail()->create();
    $account->organization->forceFill(['google_pubsub_topic' => 'projects/acme/topics/mail'])->save();

    $api = FakeGmailApi::install()->json(['historyId' => '1', 'expiration' => (string) now()->addDays(7)->getTimestampMs()]);

    app(GmailWatchManager::class)->watch($account);

    expect($api->requestJson(0)['topicName'])->toBe('projects/acme/topics/mail');
});

it('renews only healthy watches that are missing or about to expire', function () {
    $this->freezeTime();

    $expiring = MailAccount::factory()->gmail()->create(['watch_expires_at' => now()->addHours(2)]);
    $missing = MailAccount::factory()->gmail()->create(['watch_expires_at' => null]);
    $fresh = MailAccount::factory()->gmail()->create(['watch_expires_at' => now()->addDays(5)]);
    $broken = MailAccount::factory()->gmail()->needsReauth()->create(['watch_expires_at' => null]);

    $renewedUntil = now()->addDays(7);

    $api = FakeGmailApi::install()
        ->json(['historyId' => '1', 'expiration' => (string) $renewedUntil->getTimestampMs()])
        ->json(['historyId' => '1', 'expiration' => (string) $renewedUntil->getTimestampMs()]);

    $renewed = app(GmailWatchManager::class)->renewExpiring();

    expect($renewed)->toBe(2)
        ->and($api->requests)->toHaveCount(2)
        ->and($expiring->fresh()->watch_expires_at?->getTimestamp())->toBe($renewedUntil->getTimestamp())
        ->and($missing->fresh()->watch_expires_at?->getTimestamp())->toBe($renewedUntil->getTimestamp())
        ->and($fresh->fresh()->watch_expires_at?->getTimestamp())->toBe(now()->addDays(5)->getTimestamp())
        ->and($broken->fresh()->watch_expires_at)->toBeNull();
});

it('flags the account for reauthorization when the watch call is unauthorized', function () {
    $account = MailAccount::factory()->gmail()->create(['watch_expires_at' => null, 'credentials' => ['access_token' => 'x', 'expires_at' => now()->addHour()->getTimestamp()]]);

    FakeGmailApi::install()->error(401, 'authError', 'Invalid Credentials');

    expect(app(GmailWatchManager::class)->renewExpiring())->toBe(0)
        ->and($account->fresh()->status)->toBe(MailAccountStatus::NeedsReauth);
});

it('runs through the artisan command', function () {
    MailAccount::factory()->gmail()->create(['watch_expires_at' => null]);

    FakeGmailApi::install()->json(['historyId' => '1', 'expiration' => (string) now()->addDays(7)->getTimestampMs()]);

    $this->artisan('mail:gmail:renew-watches')->expectsOutputToContain('Renewed 1 Gmail watch(es).')->assertSuccessful();
});
