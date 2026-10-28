<?php

use App\Enums\MailAccountStatus;
use App\Enums\MessageDirection;
use App\Jobs\Mail\IngestForwardedEmail;
use App\Models\Conversation;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\WebhookReceipt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Store a raw message as the webhook controller would and return the claimed receipt.
 *
 * @param  array<string, mixed>  $payload
 */
function queueForwardedMessage(MailAccount $account, string $raw, array $payload = []): WebhookReceipt
{
    $path = 'inbound/'.Str::uuid().'.eml';
    Storage::disk('local')->put($path, $raw);

    return WebhookReceipt::claim('cloudflare', 'cf-'.Str::uuid(), $account->id, array_merge([
        'to' => $account->email_address,
        'from' => 'ada@example.org',
        'hops' => 1,
        'received_at' => '2026-10-28T09:16:00+00:00',
        'path' => $path,
    ], $payload));
}

function forwardingFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/mail/{$name}"));
}

function runIngestJob(MailAccount $account, WebhookReceipt $receipt): void
{
    IngestForwardedEmail::dispatchSync($account->id, $receipt->id, $receipt->payload['path']);
}

beforeEach(function () {
    Storage::fake('local');
    config()->set('tandem.attachments.disk', 'local');
    config()->set('tandem.forwarding.domain', 'forward.tandem.test');

    $this->account = MailAccount::factory()->forwarding()->create([
        'email_address' => 'x7k2m9qp4a@forward.tandem.test',
        'last_synced_at' => null,
        'sync_state' => ['received_count' => 0],
    ]);
});

it('ingests a forwarded message into the mapped inbox and cleans up', function () {
    $receipt = queueForwardedMessage($this->account, forwardingFixture('simple.eml'));

    runIngestJob($this->account, $receipt);

    $message = Message::firstOrFail();
    $conversation = Conversation::firstOrFail();

    expect($message->mail_account_id)->toBe($this->account->id)
        ->and($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->provider_message_id)->toBe('fwd:'.sha1('order-42@example.org'))
        ->and($message->subject)->toBe('Analytical engine order #42')
        ->and($message->received_at->toIso8601String())->toBe('2026-10-28T09:16:00+00:00')
        ->and($message->provider_labels)->toBe(['INBOX'])
        ->and($conversation->inbox_id)->toBe($this->account->inbox_id)
        ->and($conversation->organization_id)->toBe($this->account->organization_id)
        ->and($conversation->in_inbox)->toBeTrue();

    $this->account->refresh();
    $receipt->refresh();

    expect($this->account->last_synced_at)->not->toBeNull()
        ->and($this->account->status)->toBe(MailAccountStatus::Connected)
        ->and($this->account->syncState('received_count'))->toBe(1)
        ->and($receipt->status)->toBe('processed')
        ->and($receipt->processed_at)->not->toBeNull()
        ->and($receipt->payload['message_id'])->toBe($message->id)
        ->and($receipt->payload['conversation_id'])->toBe($conversation->id)
        ->and(Storage::disk('local')->exists($receipt->payload['path']))->toBeFalse();
});

it('does not create a second message when the same mail is delivered twice', function () {
    $first = queueForwardedMessage($this->account, forwardingFixture('simple.eml'));
    $second = queueForwardedMessage($this->account, forwardingFixture('simple.eml'));

    runIngestJob($this->account, $first);
    runIngestJob($this->account, $second);

    expect(Message::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and($second->fresh()->status)->toBe('processed')
        ->and($this->account->fresh()->syncState('received_count'))->toBe(2);
});

it('skips messages that travelled through too many hops', function () {
    $receipt = queueForwardedMessage($this->account, forwardingFixture('simple.eml'), ['hops' => 4]);

    runIngestJob($this->account, $receipt);

    expect(Message::count())->toBe(0)
        ->and($receipt->fresh()->status)->toBe('skipped')
        ->and($receipt->fresh()->payload['skipped_reason'])->toBe('too_many_hops')
        ->and(Storage::disk('local')->exists($receipt->payload['path']))->toBeFalse()
        ->and($this->account->fresh()->last_synced_at)->toBeNull();
});

it('skips our own outbound mail that bounces back through the forwarding domain', function () {
    $raw = "X-Tandem-Message: 7f1c9d1e-0000-4000-8000-000000000001\r\n".forwardingFixture('simple.eml');
    $receipt = queueForwardedMessage($this->account, $raw);

    runIngestJob($this->account, $receipt);

    expect(Message::count())->toBe(0)
        ->and($receipt->fresh()->payload['skipped_reason'])->toBe('own_outbound_message');
});

it('skips mail sent from a forwarding address of the same organization', function () {
    $sibling = MailAccount::factory()->forwarding()->create([
        'inbox_id' => $this->account->inbox_id,
        'organization_id' => $this->account->organization_id,
        'email_address' => 'bcd4h5j6k7@forward.tandem.test',
    ]);
    $raw = str_replace('From: "Ada Lovelace" <ada@example.org>', 'From: "Loop" <'.$sibling->email_address.'>', forwardingFixture('simple.eml'));
    $receipt = queueForwardedMessage($this->account, $raw);

    runIngestJob($this->account, $receipt);

    expect(Message::count())->toBe(0)
        ->and($receipt->fresh()->payload['skipped_reason'])->toBe('from_forwarding_address');
});

it('skips auto-submitted mail that is addressed only to the forwarding address', function () {
    $raw = str_replace(
        ['To: support@acme.test', 'Cc: "Charles Babbage" <charles@example.org>'],
        ['To: x7k2m9qp4a@forward.tandem.test', 'Auto-Submitted: auto-replied'],
        forwardingFixture('simple.eml'),
    );
    $receipt = queueForwardedMessage($this->account, $raw);

    runIngestJob($this->account, $receipt);

    expect(Message::count())->toBe(0)
        ->and($receipt->fresh()->payload['skipped_reason'])->toBe('auto_submitted_to_forwarding_address');
});

it('keeps auto-submitted mail that also addresses a human recipient', function () {
    $raw = str_replace('Cc: "Charles Babbage" <charles@example.org>', "Cc: x7k2m9qp4a@forward.tandem.test\nAuto-Submitted: auto-replied", forwardingFixture('simple.eml'));
    $receipt = queueForwardedMessage($this->account, $raw);

    runIngestJob($this->account, $receipt);

    expect(Message::count())->toBe(1)
        ->and($receipt->fresh()->status)->toBe('processed');
});

it('marks the receipt failed without retrying when the raw file disappeared', function () {
    $receipt = queueForwardedMessage($this->account, forwardingFixture('simple.eml'));
    Storage::disk('local')->delete($receipt->payload['path']);

    runIngestJob($this->account, $receipt);

    expect(Message::count())->toBe(0)
        ->and($receipt->fresh()->status)->toBe('failed')
        ->and($receipt->fresh()->payload['error'])->toContain('missing')
        ->and($receipt->fresh()->payload['final'])->toBeTrue();
});

it('records the final failure on the receipt and account but keeps the raw file', function () {
    $receipt = queueForwardedMessage($this->account, forwardingFixture('simple.eml'));

    (new IngestForwardedEmail($this->account->id, $receipt->id, $receipt->payload['path']))->failed(new RuntimeException('database exploded'));

    expect($receipt->fresh()->status)->toBe('failed')
        ->and($receipt->fresh()->payload['error'])->toBe('database exploded')
        ->and($this->account->fresh()->last_error)->toBe('database exploded')
        ->and(Storage::disk('local')->exists($receipt->payload['path']))->toBeTrue();
});

it('skips when the forwarding account was deleted in the meantime', function () {
    $receipt = queueForwardedMessage($this->account, forwardingFixture('simple.eml'));
    $this->account->delete();

    runIngestJob($this->account, $receipt);

    expect(Message::count())->toBe(0)
        ->and($receipt->fresh()->payload['skipped_reason'])->toBe('account_missing');
});
