<?php

namespace App\Http\Controllers\Mail;

use App\Enums\InboxRole;
use App\Enums\MailProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreForwardingAddressRequest;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\Data\Address;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Outbound\FallbackMailer;
use App\Services\Mail\Providers\Forwarding\ForwardingAddressGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Lets inbox admins create, remove and test the forwarding addresses of a shared inbox.
 */
class ForwardingAddressController extends Controller
{
    public function store(StoreForwardingAddressRequest $request, Inbox $inbox, ForwardingAddressGenerator $generator): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeInboxAdmin($inbox, $user);

        $account = $generator->generate($inbox, $user, $request->validated('label'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Forwarding address :address created.', ['address' => $account->email_address])]);

        return back();
    }

    public function destroy(Request $request, MailAccount $account): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeForwardingAccount($account, $user);

        $account->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Forwarding address removed. Mail sent to it will no longer arrive in this inbox.')]);

        return back();
    }

    public function test(Request $request, MailAccount $account, FallbackMailer $mailer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeForwardingAccount($account, $user);

        $sentAt = now();
        $inboxName = $account->inbox->name;

        $mailer->send($account, new OutgoingMessage(
            from: new Address((string) config('mail.from.address'), (string) config('mail.from.name')),
            to: [new Address($account->email_address)],
            subject: 'Tandem forwarding test',
            htmlBody: sprintf(
                '<p>This is a test message from Tandem.</p><p>If you can read this inside the <strong>%s</strong> inbox, forwarding to <code>%s</code> works.</p><p>Sent by %s at %s.</p>',
                e($inboxName),
                e($account->email_address),
                e($user->name),
                e($sentAt->toDayDateTimeString()),
            ),
            textBody: sprintf(
                "This is a test message from Tandem.\n\nIf you can read this inside the \"%s\" inbox, forwarding to %s works.\n\nSent by %s at %s.",
                $inboxName,
                $account->email_address,
                $user->name,
                $sentAt->toDayDateTimeString(),
            ),
            headers: ['X-Tandem-Forwarding-Test' => (string) Str::uuid()],
        ));

        $account->mergeSyncState(['last_test_sent_at' => $sentAt->toIso8601String()]);

        return response()->json(['status' => 'sent', 'address' => $account->email_address, 'sent_at' => $sentAt->toIso8601String()]);
    }

    protected function authorizeInboxAdmin(Inbox $inbox, User $user): void
    {
        abort_unless($inbox->roleFor($user) === InboxRole::Admin, 403, __('Only inbox admins can manage forwarding addresses.'));
    }

    protected function authorizeForwardingAccount(MailAccount $account, User $user): void
    {
        abort_unless($account->provider === MailProvider::Forwarding, 404);

        $this->authorizeInboxAdmin($account->inbox, $user);
    }
}
