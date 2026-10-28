<?php

use App\Enums\MailAccountStatus;
use App\Enums\SyncRunType;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\MailAccount;
use App\Services\Mail\Providers\Imap\ImapClientFactory;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Mail\FakeImapSession;

beforeEach(function () {
    Queue::fake();
    config()->set('tandem.imap.poll_minutes', 2);
});

test('the poll command only queues syncs for imap accounts that are due', function () {
    $due = MailAccount::factory()->create(['last_synced_at' => now()->subMinutes(10), 'last_sync_started_at' => now()->subMinutes(10)]);
    $neverSynced = MailAccount::factory()->create(['last_synced_at' => null, 'last_sync_started_at' => null]);
    $errored = MailAccount::factory()->create(['status' => MailAccountStatus::Error, 'last_synced_at' => now()->subHour(), 'last_sync_started_at' => now()->subHour()]);
    MailAccount::factory()->create(['last_synced_at' => now()->subSeconds(30)]);
    MailAccount::factory()->needsReauth()->create(['last_synced_at' => now()->subHour()]);
    MailAccount::factory()->create(['status' => MailAccountStatus::Disconnected, 'last_synced_at' => now()->subHour()]);
    MailAccount::factory()->create(['status' => MailAccountStatus::Pending, 'last_synced_at' => null]);
    MailAccount::factory()->gmail()->create(['last_synced_at' => now()->subHour()]);
    MailAccount::factory()->create(['last_synced_at' => now()->subHour(), 'last_sync_started_at' => now()->subSeconds(20)]);

    $this->artisan('mail:imap:poll')->assertSuccessful()->expectsOutputToContain('Queued 3 IMAP sync(s).');

    Queue::assertPushed(SyncMailAccount::class, 3);

    foreach ([$due, $neverSynced, $errored] as $account) {
        Queue::assertPushed(SyncMailAccount::class, fn (SyncMailAccount $job) => $job->account->is($account) && $job->type === SyncRunType::Incremental);
    }
});

test('the idle command queues a sync when the server reports a change', function () {
    $account = MailAccount::factory()->create();
    $session = (new FakeImapSession)->addFolder('INBOX');
    $session->idleResult = true;

    $this->mock(ImapClientFactory::class, fn ($mock) => $mock->shouldReceive('make')->once()->andReturn($session));

    $this->artisan('mail:imap:idle', ['account' => $account->uuid, '--once' => true, '--timeout' => 60])->assertSuccessful();

    expect($session->calls('idle'))->toBe(1)->and($session->closed)->toBeTrue();
    Queue::assertPushed(SyncMailAccount::class, fn (SyncMailAccount $job) => $job->account->is($account));
});

test('the idle command stays quiet when nothing changed', function () {
    $account = MailAccount::factory()->create();
    $session = (new FakeImapSession)->addFolder('INBOX');

    $this->mock(ImapClientFactory::class, fn ($mock) => $mock->shouldReceive('make')->once()->andReturn($session));

    $this->artisan('mail:imap:idle', ['account' => $account->uuid, '--once' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});
