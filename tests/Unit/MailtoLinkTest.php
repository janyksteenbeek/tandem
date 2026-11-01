<?php

use App\Services\Mail\MailtoLink;

test('addresses, headers and encoded text are parsed', function () {
    $link = MailtoLink::parse('mailto:ann@example.com,bob@example.com?cc=carol@example.com&bcc=dan@example.com&subject=Hello%20there&body=Line%20one%0ALine%20two%0A%0ANext%20paragraph');

    expect($link)->not->toBeNull()
        ->and($link->to)->toBe(['ann@example.com', 'bob@example.com'])
        ->and($link->cc)->toBe(['carol@example.com'])
        ->and($link->bcc)->toBe(['dan@example.com'])
        ->and($link->subject)->toBe('Hello there')
        ->and($link->body)->toBe("Line one\nLine two\n\nNext paragraph");
});

test('a to header, display names and duplicates are folded into the recipients', function () {
    $link = MailtoLink::parse('mailto:Ann%20Example%20%3Cann@example.com%3E?to=ANN@example.com;bob@example.com');

    expect($link->to)->toBe(['ann@example.com', 'bob@example.com']);
});

test('invalid addresses and unknown headers are dropped', function () {
    $link = MailtoLink::parse('mailto:not-an-address,ann@example.com?x-priority=1&subject=Hi');

    expect($link->to)->toBe(['ann@example.com'])
        ->and($link->subject)->toBe('Hi');
});

test('non-mailto links are rejected and empty links report as empty', function () {
    expect(MailtoLink::parse('https://example.com'))->toBeNull()
        ->and(MailtoLink::parse('mailto:')->isEmpty())->toBeTrue()
        ->and(MailtoLink::parse('MAILTO:ann@example.com')->isEmpty())->toBeFalse();
});

test('the prefill escapes the body and turns it into paragraphs', function () {
    $prefill = MailtoLink::parse('mailto:ann@example.com?subject=Re%3A%20%3Cplan%3E&body=1%20%3C%202%0Astill%0A%0Adone')->toPrefill();

    expect($prefill['to'])->toBe([['name' => null, 'email' => 'ann@example.com']])
        ->and($prefill['subject'])->toBe('Re: <plan>')
        ->and($prefill['html_body'])->toBe('<p>1 &lt; 2<br>still</p><p>done</p>');
});

test('control characters and oversized text are trimmed', function () {
    $link = MailtoLink::parse('mailto:?subject='.rawurlencode("Hi\x07there").'&body='.rawurlencode(str_repeat('a', 25000)));

    expect($link->subject)->toBe('Hi there')
        ->and(mb_strlen((string) $link->body))->toBe(20000);
});
