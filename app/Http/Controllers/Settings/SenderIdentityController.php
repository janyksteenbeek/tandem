<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\MailAccount;
use App\Models\SenderIdentity;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SenderIdentityController extends Controller
{
    public function store(Request $request, MailAccount $account, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $this->authorize('manage', $account);

        $data = $this->validated($request);

        if ($data['is_default'] ?? false) {
            $account->identities()->update(['is_default' => false]);
        }

        $account->identities()->create([
            ...$data,
            'signature_html' => $sanitizer->sanitize($data['signature_html'] ?? null),
            'is_default' => ($data['is_default'] ?? false) || $account->identities()->count() === 0,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Identity added.')]);

        return back();
    }

    public function update(Request $request, SenderIdentity $identity, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $this->authorize('manage', $identity->mailAccount);

        $data = $this->validated($request, $identity);

        if ($data['is_default'] ?? false) {
            $identity->mailAccount->identities()->whereKeyNot($identity->id)->update(['is_default' => false]);
        }

        $identity->update([...$data, 'signature_html' => $sanitizer->sanitize($data['signature_html'] ?? null)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Identity saved.')]);

        return back();
    }

    public function destroy(Request $request, SenderIdentity $identity): RedirectResponse
    {
        $this->authorize('manage', $identity->mailAccount);

        abort_if($identity->mailAccount->identities()->count() <= 1, 422, 'An account needs at least one identity.');

        $wasDefault = $identity->is_default;
        $identity->delete();

        if ($wasDefault) {
            $identity->mailAccount->identities()->first()?->update(['is_default' => true]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?SenderIdentity $identity = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'reply_to' => ['nullable', 'email', 'max:190'],
            'signature_html' => ['nullable', 'string', 'max:50000'],
            'is_default' => ['boolean'],
        ]);
    }
}
