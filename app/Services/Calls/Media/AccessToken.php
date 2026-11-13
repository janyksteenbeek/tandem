<?php

namespace App\Services\Calls\Media;

use RuntimeException;

/**
 * Signs the HS256 JSON Web Tokens the media server accepts: participant tokens for browsers
 * and short-lived service tokens for this application's own API calls.
 */
class AccessToken
{
    public function __construct(protected ?string $apiKey = null, protected ?string $apiSecret = null)
    {
        $this->apiKey ??= config('calls.livekit.api_key');
        $this->apiSecret ??= config('calls.livekit.api_secret');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->apiSecret);
    }

    /**
     * @param  array<string, mixed>  $grant  The "video" grant: room, roomJoin, canPublish, ...
     * @param  array<string, mixed>  $claims  Extra top-level claims such as name and metadata.
     */
    public function sign(string $identity, array $grant, int $ttl, array $claims = []): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The media server key pair (LIVEKIT_API_KEY / LIVEKIT_API_SECRET) is not configured.');
        }

        $now = time();

        $payload = [
            ...$claims,
            'iss' => $this->apiKey,
            'sub' => $identity,
            'jti' => bin2hex(random_bytes(8)),
            'nbf' => $now - 10,
            'iat' => $now,
            'exp' => $now + $ttl,
            'video' => $grant,
        ];

        $head = $this->encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $this->encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $head.'.'.$body.'.'.$this->encode(hash_hmac('sha256', $head.'.'.$body, (string) $this->apiSecret, true));
    }

    /**
     * Verify a token signed with our key pair and return its claims, or null when the
     * signature, issuer or validity window is wrong.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || ! $this->isConfigured()) {
            return null;
        }

        [$head, $body, $signature] = $parts;
        $expected = $this->encode(hash_hmac('sha256', $head.'.'.$body, (string) $this->apiSecret, true));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $header = json_decode($this->decode($head), true);
        $claims = json_decode($this->decode($body), true);

        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ! is_array($claims)) {
            return null;
        }

        $now ??= time();

        if (($claims['iss'] ?? null) !== $this->apiKey || (int) ($claims['exp'] ?? 0) < $now || (int) ($claims['nbf'] ?? 0) > $now + 60) {
            return null;
        }

        return $claims;
    }

    protected function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    protected function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
