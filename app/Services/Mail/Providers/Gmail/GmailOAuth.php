<?php

namespace App\Services\Mail\Providers\Gmail;

use App\Enums\InboxType;
use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Enums\SyncRunType;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\User;
use App\Services\Mail\Exceptions\ProviderException;
use Google\Client as GoogleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives the Google OAuth consent flow and turns a granted token into a connected MailAccount.
 */
class GmailOAuth
{
    public const string SESSION_KEY = 'tandem.gmail.oauth';

    /**
     * How long a pending consent flow stays valid.
     */
    public const int STATE_TTL_SECONDS = 900;

    public function __construct(
        protected GmailClientFactory $clients,
        protected GmailWatchManager $watches,
    ) {}

    /**
     * Build the Google consent URL and remember the intent (which inbox, which user) in the session.
     *
     * @param  array{user_id: int, inbox_uuid?: string|null}  $state
     */
    public function authorizationUrl(Organization $organization, array $state): string
    {
        $nonce = Str::random(40);

        session([self::SESSION_KEY => [
            'state' => $nonce,
            'organization_id' => $organization->id,
            'user_id' => $state['user_id'],
            'inbox_uuid' => $state['inbox_uuid'] ?? null,
            'created_at' => now()->getTimestamp(),
        ]]);

        $client = $this->consentClient($organization);
        $client->setState($nonce);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client->createAuthUrl();
    }

    /**
     * Validate the callback, exchange the code and create or refresh the MailAccount.
     */
    public function handleCallback(Request $request): MailAccount
    {
        $pending = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($pending) || ! is_string($request->query('state')) || ! hash_equals((string) ($pending['state'] ?? ''), $request->query('state'))) {
            throw new ProviderException('The Google sign-in could not be verified. Please start again.');
        }

        if (now()->getTimestamp() - (int) ($pending['created_at'] ?? 0) > self::STATE_TTL_SECONDS) {
            throw new ProviderException('The Google sign-in took too long. Please start again.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new ProviderException('Google did not return an authorization code.');
        }

        $organization = Organization::query()->whereKey($pending['organization_id'] ?? null)->first();
        $user = User::query()->whereKey($pending['user_id'] ?? null)->firstOrFail();
        $inbox = isset($pending['inbox_uuid']) && $pending['inbox_uuid'] ? Inbox::query()->where('uuid', $pending['inbox_uuid'])->firstOrFail() : null;

        $client = $this->consentClient($organization);
        $token = $this->exchangeCode($client, $code);
        $profile = $this->fetchProfile($client);

        $account = DB::transaction(fn () => $this->storeAccount($organization, $user, $inbox, $token, $profile));

        $this->registerWatch($account);

        SyncMailAccount::dispatch($account, $account->initial_sync_completed_at ? SyncRunType::Incremental : SyncRunType::Initial);

        return $account;
    }

    protected function consentClient(?Organization $organization): GoogleClient
    {
        $client = $this->clients->oauthClient($organization);
        $client->setRedirectUri(route(config('services.google.redirect', 'mail.gmail.callback')));

        return $client;
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, scope?: string}
     */
    protected function exchangeCode(GoogleClient $client, string $code): array
    {
        try {
            $token = $client->fetchAccessTokenWithAuthCode($code);
        } catch (Throwable $e) {
            throw new ProviderException('Could not exchange the Google authorization code: '.$e->getMessage(), 0, $e);
        }

        if (isset($token['error']) || empty($token['access_token'])) {
            $error = (string) ($token['error_description'] ?? $token['error'] ?? 'unknown error');

            throw new ProviderException('Google rejected the authorization code: '.$error);
        }

        /** @var array{access_token: string, refresh_token?: string, expires_in?: int, scope?: string} $token */
        return $token;
    }

    /**
     * @return array{email: string, name: string|null}
     */
    protected function fetchProfile(GoogleClient $client): array
    {
        try {
            $info = $this->clients->oauth2For($client)->userinfo->get();
        } catch (Throwable $e) {
            throw new ProviderException('Could not read the Google account profile: '.$e->getMessage(), 0, $e);
        }

        $email = Str::lower(trim((string) $info->getEmail()));

        if ($email === '') {
            throw new ProviderException('Google did not share the account email address. Make sure the email scope is granted.');
        }

        return ['email' => $email, 'name' => filled($info->getName()) ? (string) $info->getName() : null];
    }

    /**
     * @param  array{access_token: string, refresh_token?: string, expires_in?: int, scope?: string}  $token
     * @param  array{email: string, name: string|null}  $profile
     */
    protected function storeAccount(?Organization $organization, User $user, ?Inbox $inbox, array $token, array $profile): MailAccount
    {
        $email = $profile['email'];

        if ($inbox) {
            $account = MailAccount::withTrashed()
                ->where('inbox_id', $inbox->id)
                ->where('email_address', $email)
                ->first();
        } else {
            $account = MailAccount::withTrashed()
                ->where('provider', MailProvider::Gmail)
                ->where('user_id', $user->id)
                ->whereNull('organization_id')
                ->where('email_address', $email)
                ->first();

            $inbox = $account?->inbox()->withTrashed()->first() ?? Inbox::create([
                'organization_id' => null,
                'user_id' => $user->id,
                'type' => InboxType::Personal,
                'name' => $email,
                'email_address' => $email,
                'icon' => 'mail',
            ]);
        }

        if ($inbox->trashed()) {
            $inbox->restore();
        }

        $account ??= new MailAccount([
            'inbox_id' => $inbox->id,
            'provider' => MailProvider::Gmail,
            'email_address' => $email,
        ]);

        if ($account->trashed()) {
            $account->restore();
        }

        $credentials = array_merge($account->credentials ?? [], array_filter([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $account->credential('refresh_token'),
            'expires_at' => now()->getTimestamp() + (int) ($token['expires_in'] ?? 3600),
            'scope' => $token['scope'] ?? $account->credential('scope'),
            'oauth_organization_id' => $organization?->id,
        ], fn ($value) => $value !== null));

        $account->forceFill([
            'organization_id' => $inbox->isPersonal() ? null : $inbox->organization_id,
            'user_id' => $inbox->isPersonal() ? $user->id : $account->user_id,
            'display_name' => $profile['name'] ?? $account->display_name,
            'credentials' => $credentials,
            'settings' => $account->settings ?? [],
            'sync_state' => $account->sync_state ?? [],
            'status' => MailAccountStatus::Pending,
            'last_error' => null,
            'error_count' => 0,
        ])->save();

        $account->identities()->firstOrCreate(
            ['email' => $email],
            ['name' => $profile['name'] ?? $email, 'is_default' => ! $account->identities()->where('is_default', true)->exists()],
        );

        return $account;
    }

    protected function registerWatch(MailAccount $account): void
    {
        if ($this->watches->topicFor($account) === null) {
            return;
        }

        try {
            $this->watches->watch($account);
        } catch (Throwable $e) {
            Log::warning('Could not register Gmail push watch after connecting', ['account_id' => $account->id, 'error' => $e->getMessage()]);
        }
    }
}
