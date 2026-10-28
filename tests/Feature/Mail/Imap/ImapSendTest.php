<?php

use App\Models\MailAccount;
use App\Services\Mail\Data\Address;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Providers\Imap\ImapClientFactory;
use App\Services\Mail\Providers\Imap\ImapProvider;
use App\Services\Mail\Providers\Imap\SmtpTransportFactory;
use Tests\Support\Mail\FakeImapSession;
use Tests\Support\Mail\FakeSmtpTransport;

beforeEach(function () {
    $this->account = MailAccount::factory()->create(['email_address' => 'support@acme.test']);
    $this->transport = new FakeSmtpTransport;
    $this->session = (new FakeImapSession)
        ->addFolder('INBOX', ['\HasNoChildren'], 100)
        ->addFolder('Sent Items', ['\HasNoChildren', '\Sent'], 101);

    $this->mock(SmtpTransportFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($this->transport));
    $this->mock(ImapClientFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($this->session));

    $this->outgoing = new OutgoingMessage(
        from: new Address('support@acme.test', 'Acme Support'),
        to: [new Address('ada@example.org', 'Ada Lovelace')],
        subject: 'Re: Analytical engine order #42',
        htmlBody: '<p>Yes, we have one in stock.</p>',
        inReplyTo: 'order-42@example.org',
        references: ['order-42@example.org'],
    );
});

test('send delivers over smtp, generates a message id and appends a copy to the sent folder', function () {
    $result = app(ImapProvider::class)->send($this->account, $this->outgoing);

    expect($result->messageId)->toEndWith('@acme.test')
        ->and($this->transport->sent)->toHaveCount(1)
        ->and($this->transport->sent[0]->toString())->toContain('Message-ID: <'.$result->messageId.'>')
        ->and($this->transport->sent[0]->toString())->toContain('In-Reply-To: <order-42@example.org>')
        ->and($this->session->folders['Sent Items']['messages'])->toHaveCount(1)
        ->and($this->session->folders['Sent Items']['messages'][1]['flags'])->toBe(['\Seen'])
        ->and($this->session->folders['Sent Items']['messages'][1]['raw'])->toContain($result->messageId)
        ->and($result->providerMessageId)->toBe('Sent Items:101:1')
        ->and($this->session->closed)->toBeTrue();
});

test('send keeps an explicit message id and skips the sent copy when disabled', function () {
    $this->account->forceFill(['settings' => array_merge($this->account->settings, ['smtp' => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'append_sent' => false]])])->save();

    $result = app(ImapProvider::class)->send($this->account, new OutgoingMessage(
        from: new Address('support@acme.test'),
        to: [new Address('ada@example.org')],
        subject: 'Hello',
        htmlBody: '<p>Hi</p>',
        messageId: '<fixed-id@acme.test>',
    ));

    expect($result->messageId)->toBe('fixed-id@acme.test')
        ->and($result->providerMessageId)->toBeNull()
        ->and($this->session->calls('append'))->toBe(0);
});
