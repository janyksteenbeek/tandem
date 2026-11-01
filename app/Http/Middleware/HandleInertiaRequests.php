<?php

namespace App\Http\Middleware;

use App\Services\Branding;
use App\Services\Mail\MailNavigation;
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
}
