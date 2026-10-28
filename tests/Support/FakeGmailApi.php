<?php

namespace Tests\Support;

use App\Services\Mail\Providers\Gmail\GmailClientFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Queues canned Google API responses and records every outgoing request (including the
 * Authorization header the client adds) without touching the network.
 */
final class FakeGmailApi
{
    public MockHandler $mock;

    /** @var array<int, RequestInterface> */
    public array $requests = [];

    private function __construct()
    {
        $this->mock = new MockHandler;
    }

    /**
     * Install the fake transport on the container's GmailClientFactory.
     */
    public static function install(): self
    {
        $fake = new self;

        $handler = function (RequestInterface $request, array $options) use ($fake): PromiseInterface {
            $fake->requests[] = $request;

            return ($fake->mock)($request, $options);
        };

        app(GmailClientFactory::class)->fakeHttp(HandlerStack::create($handler));

        return $fake;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function json(array $body, int $status = 200, array $headers = []): self
    {
        $this->mock->append(new Response($status, ['Content-Type' => 'application/json'] + $headers, (string) json_encode($body)));

        return $this;
    }

    /**
     * Queue a Google API error envelope (`{"error": {"code", "message", "errors": [{"reason"}]}}`).
     *
     * @param  array<string, string>  $headers
     */
    public function error(int $status, string $reason, string $message = 'Error', array $headers = []): self
    {
        return $this->json([
            'error' => ['code' => $status, 'message' => $message, 'errors' => [['domain' => 'global', 'reason' => $reason, 'message' => $message]]],
        ], $status, $headers);
    }

    /**
     * Queue a `messages.get?format=raw` style response for the given MIME source.
     *
     * @param  array<int, string>  $labels
     */
    public function rawMessage(string $id, string $threadId, array $labels, string $mime, ?int $internalDateMs = null): self
    {
        return $this->json([
            'id' => $id,
            'threadId' => $threadId,
            'labelIds' => $labels,
            'snippet' => '',
            'historyId' => '1',
            'internalDate' => (string) ($internalDateMs ?? now()->getTimestampMs()),
            'sizeEstimate' => strlen($mime),
            'raw' => self::base64url($mime),
        ]);
    }

    /**
     * Queue a `messages.get?format=minimal` style response.
     *
     * @param  array<int, string>  $labels
     */
    public function minimalMessage(string $id, string $threadId, array $labels): self
    {
        return $this->json(['id' => $id, 'threadId' => $threadId, 'labelIds' => $labels, 'historyId' => '1']);
    }

    public function request(int $index): RequestInterface
    {
        return $this->requests[$index];
    }

    /**
     * @return array<string, mixed>
     */
    public function requestJson(int $index): array
    {
        $decoded = json_decode((string) $this->request($index)->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, string>
     */
    public function urls(): array
    {
        return array_map(fn (RequestInterface $request) => (string) $request->getUri(), $this->requests);
    }

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
