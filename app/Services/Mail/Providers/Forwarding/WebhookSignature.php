<?php

namespace App\Services\Mail\Providers\Forwarding;

/**
 * Shared signing scheme between the Cloudflare Email Worker and Tandem.
 *
 * Header format: X-Tandem-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "{t}.{rawBody}")>
 *
 * The JavaScript implementation lives in cloudflare/email-worker/src/signature.js and both
 * sides are pinned to the same known vector in their test suites.
 */
class WebhookSignature
{
    public const HEADER = 'X-Tandem-Signature';

    public const TOLERANCE_SECONDS = 300;

    public function sign(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, $this->digest($secret, $timestamp, $body));
    }

    public function verify(string $secret, string $body, ?string $header, ?int $now = null, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        if ($secret === '' || $header === null) {
            return false;
        }

        $parts = $this->parse($header);

        if ($parts === null) {
            return false;
        }

        if (abs(($now ?? time()) - $parts['timestamp']) > $tolerance) {
            return false;
        }

        return hash_equals($this->digest($secret, $parts['timestamp'], $body), $parts['signature']);
    }

    public function digest(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * @return array{timestamp: int, signature: string}|null
     */
    public function parse(string $header): ?array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);

            if ($key === 't' && $value !== null && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && $value !== null && preg_match('/^[a-f0-9]{64}$/', $value)) {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null) {
            return null;
        }

        return ['timestamp' => $timestamp, 'signature' => $signature];
    }
}
