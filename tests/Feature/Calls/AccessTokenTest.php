<?php

use App\Services\Calls\Media\AccessToken;

/**
 * The media server only trusts tokens signed with the shared key pair, and this application
 * only trusts webhooks signed the same way. Both directions go through this class.
 */
beforeEach(function () {
    $this->tokens = new AccessToken('key', 'a-secret-that-is-long-enough-0123456789');
});

test('a signed token verifies and carries its identity, grant and expiry', function () {
    $claims = $this->tokens->verify($this->tokens->sign('p_abc', ['room' => 'call-1', 'roomJoin' => true], 600, ['name' => 'Ada']));

    expect($claims['sub'])->toBe('p_abc')
        ->and($claims['iss'])->toBe('key')
        ->and($claims['name'])->toBe('Ada')
        ->and($claims['video'])->toBe(['room' => 'call-1', 'roomJoin' => true])
        ->and($claims['exp'] - $claims['iat'])->toBe(600);
});

test('a token is rejected when it expired, was altered, or was signed by another key', function () {
    $token = $this->tokens->sign('p_abc', ['room' => 'call-1'], 60);
    [$head, $body, $signature] = explode('.', $token);
    $forgedBody = rtrim(strtr(base64_encode((string) json_encode(['iss' => 'key', 'sub' => 'p_abc', 'exp' => time() + 60, 'video' => ['room' => 'call-1', 'roomAdmin' => true]])), '+/', '-_'), '=');

    expect($this->tokens->verify($token, time() + 120))->toBeNull()
        ->and($this->tokens->verify($head.'.'.$forgedBody.'.'.$signature))->toBeNull()
        ->and((new AccessToken('key', 'some-other-secret-some-other-secret-00'))->verify($token))->toBeNull()
        ->and((new AccessToken('other-key', 'a-secret-that-is-long-enough-0123456789'))->verify($token))->toBeNull()
        ->and($this->tokens->verify('not-a-token'))->toBeNull();
});

test('an unsigned "alg none" token is rejected', function () {
    $encode = fn (array $data) => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    $token = $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode(['iss' => 'key', 'exp' => time() + 60]).'.';

    expect($this->tokens->verify($token))->toBeNull();
});

test('signing without a configured key pair fails loudly', function () {
    config(['calls.livekit.api_key' => null, 'calls.livekit.api_secret' => null]);

    expect(fn () => (new AccessToken)->sign('p_abc', [], 60))->toThrow(RuntimeException::class, 'LIVEKIT_API_KEY');
});
