<?php

use App\Enums\MailAccountStatus;
use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\MailAccount;
use App\Models\SyncRun;
use App\Services\Mail\MailProviderManager;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeGmailApi;

it('marks the account as needing reauthorization when the refresh token is rejected', function () {
    $account = MailAccount::factory()->gmail()->create([
        'credentials' => ['access_token' => 'stale', 'refresh_token' => 'revoked', 'expires_at' => now()->subHour()->getTimestamp()],
    ]);

    $api = FakeGmailApi::install()->json(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400);

    (new SyncMailAccount($account, SyncRunType::Incremental))->handle(app(MailProviderManager::class));

    $account->refresh();

    expect($account->status)->toBe(MailAccountStatus::NeedsReauth)
        ->and($account->last_error)->toContain('invalid_grant')
        ->and($account->error_count)->toBe(1);

    $run = SyncRun::query()->where('mail_account_id', $account->id)->firstOrFail();
    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->type)->toBe(SyncRunType::Incremental);

    expect($api->requests)->toHaveCount(1)
        ->and((string) $api->request(0)->getUri())->toBe('https://oauth2.googleapis.com/token')
        ->and((string) $api->request(0)->getBody())->toContain('grant_type=refresh_token', 'refresh_token=revoked');
});

it('persists a refreshed access token before calling the API', function () {
    $account = MailAccount::factory()->gmail()->create([
        'credentials' => ['access_token' => 'stale', 'refresh_token' => 'still-valid', 'expires_at' => now()->subMinute()->getTimestamp()],
    ]);

    $api = FakeGmailApi::install()
        ->json(['access_token' => 'fresh-token', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/gmail.modify', 'token_type' => 'Bearer'])
        ->json(['history' => [], 'historyId' => '1000']);

    (new SyncMailAccount($account, SyncRunType::Incremental))->handle(app(MailProviderManager::class));

    $account->refresh();

    expect($account->status)->toBe(MailAccountStatus::Connected)
        ->and($account->credential('access_token'))->toBe('fresh-token')
        ->and($account->credential('refresh_token'))->toBe('still-valid')
        ->and($account->credential('expires_at'))->toBeGreaterThan(now()->getTimestamp())
        ->and($api->request(1)->getHeaderLine('Authorization'))->toBe('Bearer fresh-token');
});

it('does not sync accounts that are disconnected or awaiting reauthorization', function () {
    $account = MailAccount::factory()->gmail()->needsReauth()->create();

    $api = FakeGmailApi::install();

    (new SyncMailAccount($account, SyncRunType::Incremental))->handle(app(MailProviderManager::class));

    expect($api->requests)->toBeEmpty()
        ->and(SyncRun::count())->toBe(0)
        ->and($account->fresh()->status)->toBe(MailAccountStatus::NeedsReauth);
});

it('re-dispatches itself while the initial sync has more pages', function () {
    Queue::fake([SyncMailAccount::class]);
    config()->set('tandem.gmail.sync_batch_size', 1);

    $account = MailAccount::factory()->gmail()->create([
        'status' => MailAccountStatus::Pending,
        'initial_sync_completed_at' => null,
        'sync_state' => [],
    ]);

    $api = FakeGmailApi::install()
        ->json(['emailAddress' => $account->email_address, 'historyId' => '777', 'messagesTotal' => 1])
        ->json(['labels' => [['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system'], ['id' => 'Label_1', 'name' => 'Clients', 'type' => 'user']]])
        ->json(['messages' => [['id' => 'msg-1', 'threadId' => 'thread-1']], 'nextPageToken' => 'page-2', 'resultSizeEstimate' => 150])
        ->rawMessage('msg-1', 'thread-1', ['INBOX'], file_get_contents(base_path('tests/Fixtures/mail/simple.eml')));

    $job = new SyncMailAccount($account, SyncRunType::Initial);
    $job->handle(app(MailProviderManager::class));

    $account->refresh();

    expect($account->syncState('history_id'))->toBe('777')
        ->and($account->syncState('initial_page_token'))->toBe('page-2')
        ->and($account->initial_sync_completed_at)->toBeNull()
        ->and($account->folders()->where('provider_id', 'Label_1')->value('name'))->toBe('Clients')
        ->and($account->messages()->count())->toBe(1)
        ->and((string) $api->request(2)->getUri())->toContain('newer_than', 'includeSpamTrash=true');

    Queue::assertPushed(SyncMailAccount::class, fn (SyncMailAccount $next) => $next->account->is($account) && $next->type === SyncRunType::Initial);
});
