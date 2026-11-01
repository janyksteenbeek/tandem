<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Sso\ResolveSsoUser;
use App\Exceptions\SsoException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sso\Sso;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\Response;

class SsoController extends Controller
{
    private const SESSION_INTENT = 'sso.intent';

    public function __construct(
        private Sso $sso,
        private ResolveSsoUser $resolveSsoUser,
    ) {}

    /**
     * Send the user to the identity provider. Signed-in users link the
     * identity to their account, or re-authenticate when confirming access
     * to a secure area; guests sign in.
     */
    public function redirect(Request $request, string $provider): Response
    {
        abort_unless($this->sso->has($provider), 404);

        $intent = ResolveSsoUser::INTENT_LOGIN;

        if ($request->user()) {
            $intent = $request->query('intent') === ResolveSsoUser::INTENT_CONFIRM
                ? ResolveSsoUser::INTENT_CONFIRM
                : ResolveSsoUser::INTENT_LINK;
        }

        $request->session()->put(self::SESSION_INTENT, $intent);

        return $this->sso->driver($provider)->redirect();
    }

    /**
     * Handle the provider sending the user back.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->sso->has($provider), 404);

        $intent = (string) $request->session()->pull(self::SESSION_INTENT, ResolveSsoUser::INTENT_LOGIN);

        if (! $request->user()) {
            $intent = ResolveSsoUser::INTENT_LOGIN;
        }

        try {
            $socialUser = $this->fetchSocialUser($request, $provider);
            $user = $this->resolveSsoUser->handle($provider, $socialUser, $request->user(), $intent);
        } catch (SsoException $exception) {
            return $this->failed($intent, $exception);
        }

        return match ($intent) {
            ResolveSsoUser::INTENT_CONFIRM => $this->confirmed($request),
            ResolveSsoUser::INTENT_LINK => $this->linked($provider),
            default => $this->login($request, $user),
        };
    }

    /**
     * @throws SsoException
     */
    private function fetchSocialUser(Request $request, string $provider): SocialiteUser
    {
        if ($request->filled('error')) {
            throw SsoException::providerFailed($this->sso->name($provider));
        }

        try {
            return $this->sso->driver($provider)->user();
        } catch (InvalidStateException|GuzzleException) {
            throw SsoException::providerFailed($this->sso->name($provider));
        }
    }

    private function login(Request $request, User $user): RedirectResponse
    {
        if (Features::enabled(Features::twoFactorAuthentication()) && $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => true,
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        return app(LoginResponse::class)->toResponse($request);
    }

    private function confirmed(Request $request): RedirectResponse
    {
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('security.edit'));
    }

    private function linked(string $provider): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':provider account connected.', ['provider' => $this->sso->name($provider)]),
        ]);

        return to_route('security.edit');
    }

    private function failed(string $intent, SsoException $exception): RedirectResponse
    {
        $route = match ($intent) {
            ResolveSsoUser::INTENT_CONFIRM => 'password.confirm',
            ResolveSsoUser::INTENT_LINK => 'security.edit',
            default => 'login',
        };

        return to_route($route)->withErrors(['sso' => $exception->getMessage()]);
    }
}
