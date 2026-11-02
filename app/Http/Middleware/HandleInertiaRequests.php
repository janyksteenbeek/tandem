<?php

namespace App\Http\Middleware;

use App\Services\Branding;
use App\Services\Mail\MailNavigation;
use App\Services\Sso\Sso;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $branding = app(Branding::class);

        return [
            ...parent::share($request),
            'name' => $branding->name(),
            'branding' => $branding->toArray(),
            'sso' => fn () => app(Sso::class)->toArray(),
            'reverb' => $this->reverb(),
            'auth' => [
                'user' => $user ? [
                    ...$user->toArray(),
                    'initials' => $user->initials(),
                    'avatar' => $user->avatarUrl(),
                    'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                ] : null,
            ],
            'mailNav' => fn () => $user ? app(MailNavigation::class)->for($user, $user->currentOrganization) : null,
            'unreadNotifications' => fn () => $user ? $user->unreadNotifications()->count() : 0,
            'canManageOrganization' => fn () => $user && $user->currentOrganization ? $user->can('manage', $user->currentOrganization) : false,
            'currentOrganization' => fn () => $user?->currentOrganization ? $user->toUserOrganization($user->currentOrganization) : null,
            'organizations' => fn () => $user?->toUserOrganizations(includeCurrent: true) ?? [],
        ];
    }

    /**
     * Reverb connection details for the browser. The app key is public by
     * design; the secret never leaves the server. Null when broadcasting is
     * not done through Reverb.
     *
     * @return array{key: string, host: string|null, port: int, scheme: string}|null
     */
    private function reverb(): ?array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return null;
        }

        $key = config('broadcasting.connections.reverb.key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        $browser = (array) config('broadcasting.connections.reverb.browser', []);

        return [
            'key' => $key,
            'host' => filled($browser['host'] ?? null) ? (string) $browser['host'] : null,
            'port' => (int) ($browser['port'] ?? 443),
            'scheme' => ($browser['scheme'] ?? 'https') === 'http' ? 'http' : 'https',
        ];
    }
}
