<?php

namespace App\Services\Sso;

use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GoogleProvider;

/**
 * Reads config/sso.php and hands out configured Socialite drivers for the
 * identity providers that are switched on.
 */
class Sso
{
    /**
     * Socialite provider classes per config key.
     *
     * @var array<string, class-string<AbstractProvider>>
     */
    private const DRIVERS = [
        'google' => GoogleProvider::class,
    ];

    /**
     * Register a Socialite driver for every known provider so that
     * Socialite::driver('google') reads config/sso.php instead of the Gmail
     * client in config/services.php.
     */
    public static function registerDrivers(): void
    {
        foreach (self::DRIVERS as $key => $driverClass) {
            Socialite::extend($key, fn ($app) => (new SocialiteManager($app))
                ->buildProvider($driverClass, (array) config("sso.providers.{$key}", [])));
        }
    }

    /**
     * Whether sign-in is restricted to identity providers. Forcing without a
     * single enabled provider would lock everyone out, so it is ignored then.
     */
    public function forced(): bool
    {
        return (bool) config('sso.force') && $this->providers() !== [];
    }

    public function linksExistingAccounts(): bool
    {
        return (bool) config('sso.link_existing_accounts', true);
    }

    /**
     * Enabled providers that have credentials, keyed by provider.
     *
     * @return array<string, array{name: string}>
     */
    public function providers(): array
    {
        $providers = [];

        foreach ((array) config('sso.providers', []) as $key => $config) {
            if (! isset(self::DRIVERS[$key]) || empty($config['enabled']) || empty($config['client_id']) || empty($config['client_secret'])) {
                continue;
            }

            $providers[$key] = ['name' => (string) ($config['name'] ?? ucfirst($key))];
        }

        return $providers;
    }

    public function has(string $provider): bool
    {
        return array_key_exists($provider, $this->providers());
    }

    public function name(string $provider): string
    {
        return $this->providers()[$provider]['name'] ?? ucfirst($provider);
    }

    /**
     * Driver for a provider, prepared for the authorisation redirect.
     */
    public function driver(string $provider): Provider
    {
        $driver = Socialite::driver($provider);

        if ($provider === 'google') {
            $parameters = ['prompt' => 'select_account'];
            $domains = $this->hostedDomains();

            if (count($domains) === 1) {
                $parameters['hd'] = $domains[0];
            }

            $driver->with($parameters);
        }

        return $driver;
    }

    /**
     * Whether the provider vouches for the email address it returned.
     */
    public function emailVerified(SocialiteUser $user): bool
    {
        $raw = $user->getRaw();

        return (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false);
    }

    /**
     * Whether the account belongs to one of the allowed Google Workspace
     * domains. Any account is allowed when no domains are configured.
     */
    public function domainAllowed(string $provider, SocialiteUser $user): bool
    {
        if ($provider !== 'google') {
            return true;
        }

        $domains = $this->hostedDomains();

        if ($domains === []) {
            return true;
        }

        $hostedDomain = $user->getRaw()['hd'] ?? null;

        return is_string($hostedDomain) && in_array(mb_strtolower($hostedDomain), $domains, true);
    }

    /**
     * Shape shared with the frontend.
     *
     * @return array{forced: bool, providers: list<array{key: string, name: string}>}
     */
    public function toArray(): array
    {
        return [
            'forced' => $this->forced(),
            'providers' => collect($this->providers())
                ->map(fn (array $provider, string $key) => ['key' => $key, 'name' => $provider['name']])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function hostedDomains(): array
    {
        $configured = config('sso.providers.google.hosted_domains');

        if (! is_string($configured) || trim($configured) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $domain) => mb_strtolower(trim($domain)),
            explode(',', $configured),
        )));
    }
}
