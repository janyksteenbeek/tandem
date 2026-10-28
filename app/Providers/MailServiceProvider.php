<?php

namespace App\Providers;

use App\Enums\MailProvider;
use App\Services\Mail\MailProviderManager;
use Illuminate\Support\ServiceProvider;
use ZBateson\MailMimeParser\MailMimeParser;

class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailMimeParser::class, fn () => new MailMimeParser);

        $this->app->singleton(MailProviderManager::class, function ($app) {
            $manager = new MailProviderManager($app);

            foreach (config('tandem.providers', []) as $type => $driver) {
                $manager->register(MailProvider::from($type), $driver);
            }

            return $manager;
        });
    }

    public function boot(): void
    {
        //
    }
}
