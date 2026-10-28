<?php

use App\Enums\MailAccountStatus;
use App\Jobs\Mail\IngestForwardedEmail;
use App\Models\MailAccount;
use App\Models\WebhookReceipt;
use App\Services\Mail\Providers\Forwarding\WebhookSignature;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const INBOUND_SECRET = 'inbound-test-secret';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function inboundPayload(string $to, array $overrides = []): array
{
    $raw = file_get_contents(base_path('tests/Fixtures/mail/simple.eml'));

    return array_merge([
        'id' => 'cf-'.fake()->uuid(),
        'to' => $to,
        'from' => 'ada@example.org',
        'size' => strlen($raw),
        'raw' => base64_encode($raw),
        'received_at' => now()->toIso8601String(),
        'headers' => ['message-id' => '<order-42@example.org>', 'subject' => 'Analytical engine order #42'],
        'hops' => 1,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 */
function postInbound(array $payload, ?string $signature = null, string $secret = INBOUND_SECRET): TestResponse
{
    $body = json_encode($payload);
    $signature ??= (new WebhookSignature)->sign($secret, $body);

    return test()->call('POST', route('webhooks.cloudflare.inbound'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TANDEM_SIGNATURE' => $signature,
    ], content: $body);
}

beforeEach(function () {
    config()->set('tandem.forwarding.webhook_secret', INBOUND_SECRET);
    config()->set('tandem.forwarding.domain', 'forward.tandem.test');

    $this->account = MailAccount::factory()->forwarding()->create(['email_address' => 'x7k2m9qp4a@forward.tandem.test']);
});

it('returns 401 when the signature header is missing', function () {
    Queue::fake([IngestForwardedEmail::class]);

    $response = test()->postJson(route('webhooks.cloudflare.inbound'), inboundPayload($this->account->email_address));

    $response->assertUnauthorized()->assertJsonPath('status', 'unauthorized');
    Queue::assertNothingPushed();
});

it('returns 401 when the signature is expired', function () {
    Queue::fake([IngestForwardedEmail::class]);
    $payload = inboundPayload($this->account->email_address);
    $signature = (new WebhookSignature)->sign(INBOUND_SECRET, json_encode($payload), now()->subSeconds(301)->timestamp);

    postInbound($payload, $signature)->assertUnauthorized();
    Queue::assertNothingPushed();
});

it('returns 401 when the body was tampered with or signed with another secret', function () {
    Queue::fake([IngestForwardedEmail::class]);
    $payload = inboundPayload($this->account->email_address);
    $signatureForOtherBody = (new WebhookSignature)->sign(INBOUND_SECRET, json_encode(array_merge($payload, ['to' => 'someone@else.test'])));

    postInbound($payload, $signatureForOtherBody)->assertUnauthorized();
    postInbound($payload, secret: 'wrong-secret')->assertUnauthorized();
    Queue::assertNothingPushed();
});

it('returns 401 when no webhook secret is configured', function () {
    config()->set('tandem.forwarding.webhook_secret', null);

    postInbound(inboundPayload($this->account->email_address))->assertUnauthorized();
});

it('returns 422 for a payload without the required fields', function () {
    postInbound(['id' => 'cf-1'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'invalid')
        ->assertJsonStructure(['errors' => ['to', 'size', 'raw']]);
});

it('returns 422 when raw is not valid base64', function () {
    postInbound(inboundPayload($this->account->email_address, ['raw' => '***not base64***']))
        ->assertStatus(422)
        ->assertJsonPath('status', 'invalid');
});

it('returns 413 when the declared or decoded size exceeds the limit', function () {
    Queue::fake([IngestForwardedEmail::class]);
    config()->set('tandem.forwarding.max_message_bytes', 100);

    postInbound(inboundPayload($this->account->email_address, ['size' => 101, 'raw' => base64_encode('tiny')]))
        ->assertStatus(413)
        ->assertJsonPath('status', 'too_large');

    postInbound(inboundPayload($this->account->email_address, ['size' => 10]))
        ->assertStatus(413);

    Queue::assertNothingPushed();
    expect(WebhookReceipt::count())->toBe(0);
});

it('stores the raw message, claims a receipt and queues ingestion for a known address', function () {
    Storage::fake('local');
    Queue::fake([IngestForwardedEmail::class]);
    $payload = inboundPayload($this->account->email_address);

    postInbound($payload)->assertStatus(202)->assertJsonPath('status', 'queued');

    $receipt = WebhookReceipt::where('provider', 'cloudflare')->where('external_id', $payload['id'])->firstOrFail();

    expect($receipt->mail_account_id)->toBe($this->account->id)
        ->and($receipt->status)->toBe('received')
        ->and($receipt->payload['to'])->toBe($this->account->email_address)
        ->and($receipt->payload['hops'])->toBe(1)
        ->and($receipt->payload)->not->toHaveKey('raw')
        ->and($receipt->payload['path'])->toStartWith('inbound/')
        ->and(Storage::disk('local')->get($receipt->payload['path']))->toBe(base64_decode($payload['raw']));

    Queue::assertPushed(IngestForwardedEmail::class, fn (IngestForwardedEmail $job) => $job->mailAccountId === $this->account->id
        && $job->receiptId === $receipt->id
        && $job->path === $receipt->payload['path']
        && $job->queue === 'mail-inbound');
});

it('resolves plus-addressed and mixed-case recipients to the same account', function () {
    Storage::fake('local');
    Queue::fake([IngestForwardedEmail::class]);

    postInbound(inboundPayload('X7K2M9QP4A+billing@Forward.Tandem.test'))->assertStatus(202)->assertJsonPath('status', 'queued');

    Queue::assertPushed(IngestForwardedEmail::class, fn (IngestForwardedEmail $job) => $job->mailAccountId === $this->account->id);
});

it('answers duplicate deliveries with 200 and queues the job only once', function () {
    Storage::fake('local');
    Queue::fake([IngestForwardedEmail::class]);
    $payload = inboundPayload($this->account->email_address);

    postInbound($payload)->assertStatus(202);
    postInbound($payload)->assertOk()->assertJsonPath('status', 'duplicate');

    Queue::assertPushed(IngestForwardedEmail::class, 1);
    expect(WebhookReceipt::count())->toBe(1);
});

it('accepts but drops mail for an unknown recipient', function () {
    Storage::fake('local');
    Queue::fake([IngestForwardedEmail::class]);
    $payload = inboundPayload('nobodyhere1@forward.tandem.test');

    postInbound($payload)->assertStatus(202)->assertJsonPath('status', 'unknown_recipient');

    $receipt = WebhookReceipt::where('external_id', $payload['id'])->firstOrFail();

    expect($receipt->status)->toBe('processed')
        ->and($receipt->processed_at)->not->toBeNull()
        ->and($receipt->payload['skipped_reason'])->toBe('unknown_recipient')
        ->and(Storage::disk('local')->allFiles('inbound'))->toBe([]);
    Queue::assertNothingPushed();
});

it('accepts but drops mail for a disconnected forwarding address', function () {
    Storage::fake('local');
    Queue::fake([IngestForwardedEmail::class]);
    $this->account->forceFill(['status' => MailAccountStatus::Disconnected])->save();

    postInbound(inboundPayload($this->account->email_address))->assertStatus(202)->assertJsonPath('status', 'unknown_recipient');

    Queue::assertNothingPushed();
});

it('does not resolve non-forwarding accounts that share the address', function () {
    Storage::fake('local');
    Queue::fake([IngestForwardedEmail::class]);
    $this->account->delete();
    MailAccount::factory()->create(['email_address' => 'x7k2m9qp4a@forward.tandem.test']);

    postInbound(inboundPayload('x7k2m9qp4a@forward.tandem.test'))->assertStatus(202)->assertJsonPath('status', 'unknown_recipient');

    Queue::assertNothingPushed();
});
