<?php

namespace App\Services\Mail;

use App\Enums\MailProvider as ProviderType;
use App\Models\MailAccount;
use App\Services\Mail\Contracts\MailProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the adapter for a mail account. Adapters are registered by provider type so tests
 * can swap in fakes with `MailProviderManager::fake(ProviderType::Gmail, $fake)`.
 */
class MailProviderManager
{
    /** @var array<string, class-string<MailProvider>|MailProvider> */
    protected array $drivers = [];

    public function __construct(protected Container $container) {}

    /**
     * @param  class-string<MailProvider>|MailProvider  $driver
     */
    public function register(ProviderType $type, string|MailProvider $driver): void
    {
        $this->drivers[$type->value] = $driver;
    }

    public function for(MailAccount $account): MailProvider
    {
        return $this->driver($account->provider);
    }

    public function driver(ProviderType $type): MailProvider
    {
        $driver = $this->drivers[$type->value] ?? null;

        if ($driver === null) {
            throw new InvalidArgumentException("No mail provider registered for [{$type->value}].");
        }

        if ($driver instanceof MailProvider) {
            return $driver;
        }

        return $this->container->make($driver);
    }

    public function has(ProviderType $type): bool
    {
        return isset($this->drivers[$type->value]);
    }
}
