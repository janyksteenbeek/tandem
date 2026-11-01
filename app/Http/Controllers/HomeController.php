<?php

namespace App\Http\Controllers;

use App\Http\Responses\Concerns\RedirectsToCurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

/**
 * There is no marketing page: the root sends guests to the login form and
 * signed-in users straight into the mail of their current organization.
 */
class HomeController extends Controller
{
    use RedirectsToCurrentOrganization;

    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user()) {
            return redirect()->intended($this->redirectPathForCurrentOrganization($request, Fortify::redirects('login')));
        }

        return to_route('login');
    }
}
