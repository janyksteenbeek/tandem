<?php

use App\Enums\SyncRunType;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\MailAccount;
use App\Models\WebhookReceipt;
use Illuminate\Support\Facades\Queue;

function pushPayload(string $email, string $historyId, string $messageId = '2070443601311540'): array
{
    $template = file_get_contents(base_path('tests/Fixtures/mail/gmail/pubsub-push.json'));
    $payload = json_decode(str_replace('__DATA__', base64_encode(json_encode(['emailAddress' => $email, 'historyId' => $historyId])), $template), true);
    $payload['message']['messageId'] = $messageId;
    $payload['message']['message_id'] = $messageId;

    return $payload;
}

beforeEach(function () {
    config()->set('tandem.gmail.pubsub_token', 'push-secret');
});

it('returns 404 for an unknown push token', function () {
    Queue::fake([SyncMailAccount::class]);

    $this->postJson('/webhooks/gmail/push/wrong-token', pushPayload('someone@gmail.com', '2000'))->assertNotFound();

    Queue::assertNothingPushed();
    expect(WebhookReceipt::count())->toBe(0);
});

it('returns 400 for a body that is not JSON', function () {
    $this->call('POST', route('webhooks.gmail.push', ['token' => 'push-secret']), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{not json')
        ->assertStatus(400);
});

it('dispatches an incremental sync when the notified history id is newer than the stored one', function () {
    Queue::fake([SyncMailAccount::class]);

    $account = MailAccount::factory()->gmail()->create(['email_address' => 'Team@Gmail.com', 'sync_state' => ['history_id' => '1000']]);

    $this->postJson(route('webhooks.gmail.push', ['token' => 'push-secret']), pushPayload('team@gmail.com', '1500'))->assertNoContent();

    Queue::assertPushed(SyncMailAccount::class, fn (SyncMailAccount $job) => $job->account->is($account) && $job->type === SyncRunType::Incremental);

    $this->assertDatabaseHas('webhook_receipts', ['provider' => 'gmail', 'external_id' => '2070443601311540', 'mail_account_id' => $account->id, 'status' => 'processed']);
});

it('processes a redelivered Pub/Sub message only once', function () {
    Queue::fake([SyncMailAccount::class]);

    MailAccount::factory()->gmail()->create(['email_address' => 'team@gmail.com', 'sync_state' => ['history_id' => '1000']]);

    $this->postJson(route('webhooks.gmail.push', ['token' => 'push-secret']), pushPayload('team@gmail.com', '1500', 'dup-1'))->assertNoContent();
    $this->postJson(route('webhooks.gmail.push', ['token' => 'push-secret']), pushPayload('team@gmail.com', '1500', 'dup-1'))->assertNoContent();

    Queue::assertPushed(SyncMailAccount::class, 1);
    expect(WebhookReceipt::count())->toBe(1);
});

it('ignores notifications whose history id is not newer than the stored cursor', function () {
    Queue::fake([SyncMailAccount::class]);

    MailAccount::factory()->gmail()->create(['email_address' => 'team@gmail.com', 'sync_state' => ['history_id' => '2000']]);

    $this->postJson(route('webhooks.gmail.push', ['token' => 'push-secret']), pushPayload('team@gmail.com', '1500'))->assertNoContent();

    Queue::assertNothingPushed();
});

it('acknowledges notifications for unknown mailboxes without dispatching anything', function () {
    Queue::fake([SyncMailAccount::class]);

    $this->postJson(route('webhooks.gmail.push', ['token' => 'push-secret']), pushPayload('nobody@gmail.com', '1500'))->assertNoContent();

    Queue::assertNothingPushed();
    $this->assertDatabaseHas('webhook_receipts', ['provider' => 'gmail', 'external_id' => '2070443601311540', 'mail_account_id' => null, 'status' => 'processed']);
});
