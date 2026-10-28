<?php

use App\Models\MailAccount;
use App\Models\Message;
use App\Services\Mail\Data\Address;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Exceptions\RateLimitedException;
use App\Services\Mail\Exceptions\TransientProviderException;
use App\Services\Mail\Providers\Gmail\GmailProvider;
use Tests\Support\FakeGmailApi;

beforeEach(function () {
    $this->account = MailAccount::factory()->gmail()->create(['email_address' => 'support@acme.test']);
});

it('sends a raw MIME message inside the existing Gmail thread and returns the provider ids', function () {
    $api = FakeGmailApi::install()->json(['id' => 'sent-1', 'threadId' => 'thread-9', 'labelIds' => ['SENT']]);

    $outgoing = new OutgoingMessage(
        from: new Address('support@acme.test', 'Acme Support'),
        to: [new Address('ada@example.org', 'Ada Lovelace')],
        subject: 'Re: Analytical engine order #42',
        htmlBody: '<p>Yes, we have one in stock.</p>',
        inReplyTo: 'order-42@example.org',
        references: ['order-42@example.org'],
        providerThreadId: 'thread-9',
    );

    $result = app(GmailProvider::class)->send($this->account, $outgoing);

    expect($result->providerMessageId)->toBe('sent-1')
        ->and($result->providerThreadId)->toBe('thread-9')
        ->and($result->messageId)->toEndWith('@acme.test')
        ->and($result->messageId)->not->toContain('<', '>');

    $request = $api->request(0);
    $body = $api->requestJson(0);
    $mime = FakeGmailApi::base64urlDecode($body['raw']);

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toContain('/gmail/v1/users/me/messages/send')
        ->and($body['threadId'])->toBe('thread-9')
        ->and($mime)->toContain('Subject: Re: Analytical engine order #42', 'To: Ada Lovelace <ada@example.org>', 'In-Reply-To: <order-42@example.org>', 'Message-ID: <'.$result->messageId.'>', 'Yes, we have one in stock.');
});

it('keeps a caller supplied Message-ID and omits the thread id for new conversations', function () {
    $api = FakeGmailApi::install()->json(['id' => 'sent-2', 'threadId' => 'thread-new']);

    $result = app(GmailProvider::class)->send($this->account, new OutgoingMessage(
        from: new Address('support@acme.test'),
        to: [new Address('ada@example.org')],
        subject: 'Hello',
        htmlBody: '<p>Hi</p>',
        messageId: '<custom-id@acme.test>',
    ));

    expect($result->messageId)->toBe('custom-id@acme.test')
        ->and($api->requestJson(0))->not->toHaveKey('threadId')
        ->and(FakeGmailApi::base64urlDecode($api->requestJson(0)['raw']))->toContain('Message-ID: <custom-id@acme.test>');
});

it('translates Gmail quota errors into a rate limit with the Retry-After delay', function () {
    FakeGmailApi::install()->error(429, 'rateLimitExceeded', 'Too many requests', ['Retry-After' => '17']);

    $message = new OutgoingMessage(from: new Address('support@acme.test'), to: [new Address('ada@example.org')], subject: 'Hello', htmlBody: '<p>Hi</p>');

    try {
        app(GmailProvider::class)->send($this->account, $message);
        $this->fail('Expected a RateLimitedException.');
    } catch (RateLimitedException $e) {
        expect($e->retryAfterSeconds)->toBe(17);
    }
});

it('translates Gmail server errors into transient failures', function () {
    FakeGmailApi::install()->error(503, 'backendError', 'Backend Error');

    $message = new OutgoingMessage(from: new Address('support@acme.test'), to: [new Address('ada@example.org')], subject: 'Hello', htmlBody: '<p>Hi</p>');

    expect(fn () => app(GmailProvider::class)->send($this->account, $message))->toThrow(TransientProviderException::class);
});

it('batch modifies labels when marking messages read', function () {
    $api = FakeGmailApi::install()->json([], 204);

    $messages = Message::factory()->count(2)->sequence(['provider_message_id' => 'a'], ['provider_message_id' => 'b'])->create([
        'mail_account_id' => $this->account->id,
    ]);

    app(GmailProvider::class)->markRead($this->account, $messages, true);

    expect((string) $api->request(0)->getUri())->toContain('/gmail/v1/users/me/messages/batchModify')
        ->and($api->requestJson(0))->toBe(['ids' => ['a', 'b'], 'removeLabelIds' => ['UNREAD']]);
});
