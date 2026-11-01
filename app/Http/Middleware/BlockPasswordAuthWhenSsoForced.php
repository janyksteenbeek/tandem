<?php

namespace App\Http\Middleware;

use App\Services\Sso\Sso;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With SSO forced, every way of authenticating that does not go through an
 * identity provider is switched off: password login, registration, password
 * resets and changes, and passkey login.
 */
class BlockPasswordAuthWhenSsoForced
{
    /**
     * @var list<string>
     */
    public const BLOCKED_ROUTES = [
        'login.store',
        'register',
        'register.store',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'user-password.update',
        'passkey.login-options',
        'passkey.login',
    ];

    public function __construct(private Sso $sso) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if (! $routeName || ! in_array($routeName, self::BLOCKED_ROUTES, true) || ! $this->sso->forced()) {
            return $next($request);
        }

        if ($request->isMethodSafe() && ! $request->expectsJson()) {
            return redirect()->route('login');
        }

        abort(403, __('Password sign-in is disabled. Use single sign-on instead.'));
    }
}
