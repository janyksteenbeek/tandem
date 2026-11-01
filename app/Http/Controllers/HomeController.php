<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * There is no marketing page: the root sends guests to the login form and
 * signed-in users straight into their mail.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user()) {
            return redirect()->intended(config('fortify.home'));
        }

        return to_route('login');
    }
}
