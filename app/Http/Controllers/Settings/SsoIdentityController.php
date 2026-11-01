<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Sso\Sso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SsoIdentityController extends Controller
{
    public function __construct(private Sso $sso) {}

    /**
     * Disconnect an identity provider from the current user.
     */
    public function destroy(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        $identity = $user->ssoIdentities()->where('provider', $provider)->firstOrFail();

        if ($this->sso->forced() && $user->ssoIdentities()->count() === 1) {
            return back()->withErrors([
                'sso' => __('Single sign-on is required here, so your last connected account cannot be removed.'),
            ]);
        }

        $identity->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':provider account disconnected.', ['provider' => $this->sso->name($provider)]),
        ]);

        return back();
    }
}
