<?php

namespace App\Services\Mail\Providers\Gmail;

use App\Models\MailAccount;
use App\Models\Organization;
use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use App\Services\Mail\Exceptions\ProviderException;
use App\Services\Mail\Exceptions\RateLimitedException;
use App\Services\Mail\Exceptions\TransientProviderException;
use Closure;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Oauth2;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Builds authenticated Google API clients for Gmail accounts. All Gmail traffic goes through
 * this class so tests can replace the HTTP transport with a Guzzle MockHandler via fakeHttp().
 */
class GmailClientFactory
{
    /**
     * Seconds of remaining lifetime below which an access token is refreshed before use.
     */
    public const int REFRESH_LEEWAY_SECONDS = 120;

    protected ?HandlerStack $fakeHandler = null;

    protected ?int $lastRetryAfterSeconds = null;

    /**
     * Route all outgoing Google API traffic through the given Guzzle handler (tests only).
     */
    public function fakeHttp(HandlerStack|callable $handler): HandlerStack
    {
        $this->fakeHandler = $handler instanceof HandlerStack ? $handler : HandlerStack::create($handler);

        return $this->fakeHandler;
    }

    public function isFaked(): bool
    {
        return $this->fakeHandler !== null;
    }

    /**
     * A bare OAuth client (no user token) configured with the credentials of the given organization,
     * falling back to the global Google credentials from config/services.php.
     */
    public function oauthClient(?Organization $organization = null): GoogleClient
    {
        [$clientId, $clientSecret] = $this->oauthCredentials($organization);

        $client = new GoogleClient;
        $client->setApplicationName(config('app.name', 'Tandem'));
        $client->setClientId((string) $clientId);
        $client->setClientSecret((string) $clientSecret);
        $client->setScopes(config('tandem.gmail.scopes', []));
        $client->setHttpClient($this->httpClient());

        return $client;
    }

    /**
     * A client carrying a valid access token for the account, refreshing it first when needed.
     */
    public function clientFor(MailAccount $account): GoogleClient
    {
        $client = $this->oauthClient($this->oauthOrganizationFor($account));

        $this->applyToken($account, $client, forceRefresh: false);

        return $client;
    }

    public function gmailFor(MailAccount $account): Gmail
    {
        return new Gmail($this->clientFor($account));
    }

    public function oauth2For(GoogleClient $client): Oauth2
    {
        return new Oauth2($client);
    }

    /**
     * Force a token refresh and persist the result. Throws when Google rejects the refresh token.
     */
    public function refreshAccessToken(MailAccount $account): void
    {
        $client = $this->oauthClient($this->oauthOrganizationFor($account));

        $this->applyToken($account, $client, forceRefresh: true);
    }

    /**
     * Run a Gmail API call for the account, translating Google errors into provider exceptions
     * and retrying once with a freshly refreshed token after a 401.
     *
     * @template TReturn
     *
     * @param  Closure(Gmail): TReturn  $callback
     * @return TReturn
     */
    public function call(MailAccount $account, Closure $callback): mixed
    {
        try {
            return $callback($this->gmailFor($account));
        } catch (GoogleServiceException $e) {
            if ($e->getCode() !== 401 || ! $account->credential('refresh_token')) {
                throw $this->translate($e);
            }
        } catch (GuzzleException $e) {
            throw new TransientProviderException($e->getMessage(), 0, $e);
        }

        $this->refreshAccessToken($account);

        try {
            return $callback($this->gmailFor($account));
        } catch (GoogleServiceException $e) {
            throw $this->translate($e);
        } catch (GuzzleException $e) {
            throw new TransientProviderException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Map a Google API failure onto the provider exception hierarchy. 404s are returned untouched
     * so callers can react to "history too old" or "message gone" themselves.
     */
    public function translate(Throwable $e): Throwable
    {
        if ($e instanceof ProviderException) {
            return $e;
        }

        if ($e instanceof GuzzleException) {
            return new TransientProviderException($e->getMessage(), 0, $e);
        }

        if (! $e instanceof GoogleServiceException) {
            return $e;
        }

        $code = (int) $e->getCode();
        $reasons = collect($e->getErrors() ?? [])->pluck('reason')->filter()->map(fn ($reason) => (string) $reason);
        $message = $this->describe($e);

        if ($code === 401 || str_contains($e->getMessage(), 'invalid_grant')) {
            return new AuthenticationRequiredException($message, $code, $e);
        }

        if ($code === 429 || ($code === 403 && $reasons->intersect(['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded'])->isNotEmpty())) {
            return new RateLimitedException($message, $this->lastRetryAfterSeconds ?? 60);
        }

        if ($code === 403 && $reasons->intersect(['insufficientPermissions', 'forbidden', 'accessNotConfigured'])->isNotEmpty()) {
            return new AuthenticationRequiredException($message, $code, $e);
        }

        if ($code >= 500 || $code === 0) {
            return new TransientProviderException($message, $code, $e);
        }

        if ($code === 404) {
            return $e;
        }

        return new ProviderException($message, $code, $e);
    }

    public function lastRetryAfterSeconds(): ?int
    {
        return $this->lastRetryAfterSeconds;
    }

    protected function applyToken(MailAccount $account, GoogleClient $client, bool $forceRefresh): void
    {
        $accessToken = $account->credential('access_token');
        $refreshToken = $account->credential('refresh_token');
        $expiresAt = (int) $account->credential('expires_at', 0);

        $needsRefresh = $forceRefresh || ! $accessToken || $expiresAt <= time() + self::REFRESH_LEEWAY_SECONDS;

        if ($needsRefresh) {
            if (! $refreshToken) {
                throw new AuthenticationRequiredException('The Gmail account has no refresh token; it must be reconnected.');
            }

            [$accessToken, $expiresAt] = $this->exchangeRefreshToken($account, $client, (string) $refreshToken);
        }

        $client->setAccessToken(array_filter([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => max(1, $expiresAt - time()),
            'created' => time(),
        ]));
    }

    /**
     * @return array{0: string, 1: int}
     */
    protected function exchangeRefreshToken(MailAccount $account, GoogleClient $client, string $refreshToken): array
    {
        try {
            $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (GuzzleException $e) {
            throw new TransientProviderException('Could not reach Google to refresh the access token: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new TransientProviderException('Unexpected response while refreshing the Google access token: '.$e->getMessage(), 0, $e);
        }

        if (isset($token['error']) || empty($token['access_token'])) {
            $error = (string) ($token['error'] ?? 'unknown_error');
            $description = (string) ($token['error_description'] ?? '');

            if (in_array($error, ['invalid_grant', 'invalid_client', 'unauthorized_client', 'invalid_rapt'], true)) {
                throw new AuthenticationRequiredException(trim("Google rejected the refresh token ({$error}). {$description}"));
            }

            throw new TransientProviderException(trim("Google token refresh failed ({$error}). {$description}"));
        }

        $expiresAt = time() + (int) ($token['expires_in'] ?? 3600);

        $account->mergeCredentials(array_filter([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $refreshToken,
            'expires_at' => $expiresAt,
            'scope' => $token['scope'] ?? null,
        ], fn ($value) => $value !== null));

        return [(string) $token['access_token'], $expiresAt];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    protected function oauthCredentials(?Organization $organization): array
    {
        if ($organization && $organization->hasGoogleOAuthCredentials()) {
            return [$organization->google_oauth_client_id, $organization->google_oauth_client_secret];
        }

        return [config('services.google.client_id'), config('services.google.client_secret')];
    }

    public function oauthOrganizationFor(MailAccount $account): ?Organization
    {
        $organizationId = $account->credential('oauth_organization_id') ?? $account->organization_id;

        if ($organizationId === null) {
            return null;
        }

        return Organization::query()->whereKey($organizationId)->first();
    }

    protected function httpClient(): HttpClient
    {
        $stack = $this->fakeHandler ?? HandlerStack::create();

        $stack->remove('tandem.retry_after');
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
            $this->lastRetryAfterSeconds = in_array($response->getStatusCode(), [403, 429, 503], true)
                ? $this->parseRetryAfter($response->getHeaderLine('Retry-After'))
                : null;

            return $response;
        }), 'tandem.retry_after');

        return new HttpClient([
            'handler' => $stack,
            'base_uri' => GoogleClient::API_BASE_PATH,
            'http_errors' => false,
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
    }

    protected function parseRetryAfter(string $header): ?int
    {
        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return max(1, (int) $header);
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            Log::debug('Unparseable Retry-After header from Google', ['header' => $header]);

            return null;
        }

        return max(1, $timestamp - time());
    }

    protected function describe(GoogleServiceException $e): string
    {
        $decoded = json_decode($e->getMessage(), true);

        if (is_array($decoded)) {
            $message = data_get($decoded, 'error.message') ?? data_get($decoded, 'error_description') ?? data_get($decoded, 'error');

            if (is_string($message) && $message !== '') {
                return sprintf('Gmail API error %d: %s', $e->getCode(), $message);
            }
        }

        return sprintf('Gmail API error %d: %s', $e->getCode(), mb_substr($e->getMessage(), 0, 500));
    }
}
