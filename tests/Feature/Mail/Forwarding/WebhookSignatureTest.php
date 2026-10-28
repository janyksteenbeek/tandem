<?php

use App\Services\Mail\Providers\Forwarding\WebhookSignature;

/*
 * Cross-language vector: cloudflare/email-worker/test/signature.test.js asserts the same
 * secret/timestamp/body produce the same v1 digest.
 */
const SIGNATURE_VECTOR_SECRET = 'tandem-shared-secret';
const SIGNATURE_VECTOR_TIMESTAMP = 1761724800;
const SIGNATURE_VECTOR_BODY = '{"id":"evt_1","to":"x7k2m9qp4a@forward.tandem.test"}';
const SIGNATURE_VECTOR_DIGEST = '2d757bd4a08d7bf921460f82dcb645d4208ad1ca492485ec0c294b55c7bd550d';

beforeEach(function () {
    $this->signature = new WebhookSignature;
});

it('produces the shared cross-language test vector', function () {
    expect($this->signature->digest(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_TIMESTAMP, SIGNATURE_VECTOR_BODY))->toBe(SIGNATURE_VECTOR_DIGEST)
        ->and($this->signature->sign(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_BODY, SIGNATURE_VECTOR_TIMESTAMP))
        ->toBe('t='.SIGNATURE_VECTOR_TIMESTAMP.',v1='.SIGNATURE_VECTOR_DIGEST);
});

it('verifies a signature made within the tolerance window', function () {
    $header = $this->signature->sign(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_BODY, SIGNATURE_VECTOR_TIMESTAMP);

    expect($this->signature->verify(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_BODY, $header, now: SIGNATURE_VECTOR_TIMESTAMP + 299))->toBeTrue()
        ->and($this->signature->verify(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_BODY, $header, now: SIGNATURE_VECTOR_TIMESTAMP - 299))->toBeTrue();
});

it('rejects an expired or future-dated signature', function () {
    $header = $this->signature->sign(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_BODY, SIGNATURE_VECTOR_TIMESTAMP);

    expect($this->signature->verify(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_BODY, $header, now: SIGNATURE_VECTOR_TIMESTAMP + 301))->toBeFalse()
        ->and($this->signature->verify(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_BODY, $header, now: SIGNATURE_VECTOR_TIMESTAMP - 301))->toBeFalse();
});

it('rejects a tampered body, wrong secret, or malformed header', function () {
    $header = $this->signature->sign(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_BODY, SIGNATURE_VECTOR_TIMESTAMP);

    expect($this->signature->verify(SIGNATURE_VECTOR_SECRET, '{"id":"evt_2"}', $header, now: SIGNATURE_VECTOR_TIMESTAMP))->toBeFalse()
        ->and($this->signature->verify('other-secret', SIGNATURE_VECTOR_BODY, $header, now: SIGNATURE_VECTOR_TIMESTAMP))->toBeFalse()
        ->and($this->signature->verify(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_BODY, 'v1=abc', now: SIGNATURE_VECTOR_TIMESTAMP))->toBeFalse()
        ->and($this->signature->verify(SIGNATURE_VECTOR_SECRET, SIGNATURE_VECTOR_BODY, null, now: SIGNATURE_VECTOR_TIMESTAMP))->toBeFalse()
        ->and($this->signature->verify('', SIGNATURE_VECTOR_BODY, $header, now: SIGNATURE_VECTOR_TIMESTAMP))->toBeFalse();
});
