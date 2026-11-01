<?php

namespace App\Providers;

use App\Events\Conversations\ConversationAssigned;
use App\Events\Conversations\ConversationMoved;
use App\Events\Conversations\ConversationStatusChanged;
use App\Events\Conversations\ConversationTagged;
use App\Events\Mail\MessageIngested;
use App\Listeners\Automations\RunAutomations;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();
        $this->registerAutomationListeners();
    }

    /**
     * Behind a load balancer or Docker ingress the X-Forwarded-* headers decide the scheme and
     * client IP (OAuth redirects, webhook signatures, rate limits). Accepts "*" or a list of IPs/CIDRs.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('tandem.trusted_proxies');

        if (! is_string($proxies) || trim($proxies) === '') {
            return;
        }

        $proxies = trim($proxies);

        TrustProxies::at(in_array($proxies, ['*', '**'], true)
            ? $proxies
            : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    }

    /**
     * Domain events that feed the automation engine. Plain event classes are not discovered, so
     * the listener is registered explicitly.
     */
    protected function registerAutomationListeners(): void
    {
        Event::listen([
            MessageIngested::class,
            ConversationAssigned::class,
            ConversationMoved::class,
            ConversationStatusChanged::class,
            ConversationTagged::class,
        ], RunAutomations::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
