<?php

namespace App\Http\Controllers\Mail;

use App\Enums\MailboxView;
use App\Http\Controllers\Controller;
use App\Services\Mail\MailNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MailHomeController extends Controller
{
    public function __invoke(Request $request, MailNavigation $navigation): RedirectResponse
    {
        $user = $request->user();
        $organization = $user->currentOrganization;
        $inbox = $navigation->defaultInbox($user, $organization);

        if (! $inbox) {
            return to_route('mail.accounts.index');
        }

        $view = $inbox->isPersonal() ? MailboxView::Inbox : MailboxView::Open;

        return to_route('mail.inbox', [
            'current_organization' => $organization?->slug,
            'inbox' => $inbox->uuid,
            'view' => $view->value,
        ]);
    }
}
