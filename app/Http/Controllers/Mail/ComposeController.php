<?php

namespace App\Http\Controllers\Mail;

use App\Enums\MailboxView;
use App\Http\Controllers\Controller;
use App\Services\Mail\MailNavigation;
use App\Services\Mail\MailtoLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Opens the composer from outside the app: the installed app's mailto:
 * handler (which passes the whole link as `mailto`), or plain `to`,
 * `subject` and `body` parameters for links of our own.
 *
 * The prefill travels as flash data to the user's default inbox, where the
 * mail page picks it up and opens the compose window.
 */
class ComposeController extends Controller
{
    public function __invoke(Request $request, MailNavigation $navigation): RedirectResponse
    {
        $user = $request->user();
        $organization = $user->currentOrganization;
        $inbox = $navigation->defaultInbox($user, $organization);

        if (! $inbox) {
            return to_route('mail.accounts.index');
        }

        $link = $this->link($request);

        if ($link !== null && ! $link->isEmpty()) {
            Inertia::flash('compose', $link->toPrefill());
        } else {
            Inertia::flash('compose', (new MailtoLink)->toPrefill());
        }

        return to_route('mail.inbox', [
            'current_organization' => $organization?->slug,
            'inbox' => $inbox->uuid,
            'view' => $inbox->isPersonal() ? MailboxView::Inbox->value : MailboxView::Open->value,
        ]);
    }

    private function link(Request $request): ?MailtoLink
    {
        $mailto = $request->query('mailto');

        if (is_string($mailto) && $mailto !== '') {
            return MailtoLink::parse($mailto);
        }

        $parts = array_filter([
            'to' => $request->query('to'),
            'cc' => $request->query('cc'),
            'bcc' => $request->query('bcc'),
            'subject' => $request->query('subject'),
            'body' => $request->query('body'),
        ], fn ($value) => is_string($value) && $value !== '');

        return MailtoLink::parse('mailto:?'.http_build_query($parts, '', '&', PHP_QUERY_RFC3986));
    }
}
