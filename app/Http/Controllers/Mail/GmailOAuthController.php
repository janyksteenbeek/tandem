<?php

namespace App\Http\Controllers\Mail;

use App\Enums\InboxRole;
use App\Http\Controllers\Controller;
use App\Models\Inbox;
use App\Services\Mail\Exceptions\ProviderException;
use App\Services\Mail\Providers\Gmail\GmailOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

class GmailOAuthController extends Controller
{
    public function __construct(protected GmailOAuth $oauth) {}

    /**
     * Send the user to Google's consent screen. With `?inbox={uuid}` the account is attached to that
     * shared inbox (admins only); without it a personal inbox is created for the Google account.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $user = $request->user();
        $inbox = null;

        if ($request->filled('inbox')) {
            $inbox = Inbox::query()->where('uuid', (string) $request->query('inbox'))->firstOrFail();

            abort_unless($inbox->isShared() && $inbox->roleFor($user) === InboxRole::Admin, 403);
        }

        $organization = $inbox->organization ?? $user->currentOrganization ?? $user->fallbackOrganization();

        abort_if($organization === null, 404);

        return redirect()->away($this->oauth->authorizationUrl($organization, [
            'user_id' => $user->id,
            'inbox_uuid' => $inbox?->uuid,
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $request->session()->forget(GmailOAuth::SESSION_KEY);

            Inertia::flash('toast', ['type' => 'error', 'message' => $request->query('error') === 'access_denied'
                ? __('Google access was denied, so the account was not connected.')
                : __('Google returned an error while connecting the account.'),
            ]);

            return redirect($this->afterConnectUrl());
        }

        try {
            $account = $this->oauth->handleCallback($request);
        } catch (ProviderException $e) {
            Log::info('Gmail OAuth callback failed', ['user_id' => $request->user()?->id, 'error' => $e->getMessage()]);

            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return redirect($this->afterConnectUrl());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':email is connected. Your mail is being imported.', ['email' => $account->email_address])]);

        return redirect($this->afterConnectUrl());
    }

    protected function afterConnectUrl(): string
    {
        return Route::has('mail.accounts.index') ? route('mail.accounts.index') : '/';
    }
}
