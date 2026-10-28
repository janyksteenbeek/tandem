<?php

namespace App\Http\Controllers\Mail;

use App\Enums\InboxRole;
use App\Enums\InboxType;
use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Enums\SyncRunType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreImapAccountRequest;
use App\Http\Requests\Mail\TestImapConnectionRequest;
use App\Http\Requests\Mail\UpdateImapAccountRequest;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\Data\ConnectionTestResult;
use App\Services\Mail\MailProviderManager;
use App\Services\Mail\Providers\Imap\ImapProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class ImapAccountController extends Controller
{
    public function __construct(protected ImapProvider $provider, protected MailProviderManager $providers) {}

    /**
     * Verify IMAP + SMTP settings without storing anything.
     */
    public function test(TestImapConnectionRequest $request): JsonResponse
    {
        $result = $this->provider->testSettings(
            $request->imapSettings(),
            $request->smtpSettings(),
            (string) $request->imapPassword(),
            $request->smtpPassword(),
        );

        return response()->json([
            'ok' => $result->ok,
            'message' => $result->message,
            'details' => $result->details,
        ], $result->ok ? 200 : 422);
    }

    /**
     * Connect a mailbox to a shared inbox (admins only) or to a new personal inbox.
     */
    public function store(StoreImapAccountRequest $request): RedirectResponse
    {
        $user = $request->user();
        $address = (string) $request->emailAddress();
        $inbox = $this->resolveInbox($request, $user);

        $existing = MailAccount::withTrashed()
            ->where('inbox_id', $inbox?->id)
            ->where('email_address', $address)
            ->first();

        if ($existing && ! $existing->trashed()) {
            throw ValidationException::withMessages(['email' => __('This mailbox is already connected to that inbox.')]);
        }

        $this->assertConnects($this->provider->testSettings(
            $request->imapSettings(),
            $request->smtpSettings(),
            (string) $request->imapPassword(),
            $request->smtpPassword(),
        ));

        $account = DB::transaction(function () use ($request, $user, $inbox, $address, $existing) {
            $inbox ??= Inbox::create([
                'organization_id' => null,
                'user_id' => $user->id,
                'type' => InboxType::Personal,
                'name' => $address,
                'email_address' => $address,
                'color' => 'violet',
                'icon' => 'mail',
                'settings' => [],
            ]);

            $attributes = [
                'organization_id' => $inbox->organization_id,
                'user_id' => $user->id,
                'inbox_id' => $inbox->id,
                'provider' => MailProvider::Imap,
                'email_address' => $address,
                'display_name' => $request->validated('name'),
                'settings' => ['imap' => $request->imapSettings(), 'smtp' => $request->smtpSettings()],
                'credentials' => array_filter(['password' => $request->imapPassword(), 'smtp_password' => $request->smtpPassword()]),
                'sync_state' => [],
                'status' => MailAccountStatus::Pending,
                'last_error' => null,
                'error_count' => 0,
                'initial_sync_completed_at' => null,
            ];

            if ($existing) {
                $existing->restore();
                $existing->forceFill($attributes)->save();
                $account = $existing;
            } else {
                $account = MailAccount::create($attributes);
            }

            $account->identities()->updateOrCreate(
                ['email' => $address],
                ['name' => $request->validated('name'), 'is_default' => true],
            );

            return $account;
        });

        try {
            $this->provider->syncFolders($account);
        } catch (Throwable $e) {
            Log::warning('IMAP folder discovery failed right after connecting; the sync job will retry', ['account_id' => $account->id, 'error' => $e->getMessage()]);
        }

        SyncMailAccount::dispatch($account, SyncRunType::Initial);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mailbox :email connected. The first sync has started.', ['email' => $address])]);

        return back();
    }

    /**
     * Update connection settings; blank passwords keep the stored ones.
     */
    public function update(UpdateImapAccountRequest $request, MailAccount $account): RedirectResponse
    {
        $this->authorizeManage($request->user(), $account);
        abort_unless($account->provider === MailProvider::Imap, 404);

        $password = $request->imapPassword() ?? $account->credential('password');
        $smtpPassword = $request->smtpPassword() ?? $account->credential('smtp_password');

        if (! is_string($password) || $password === '') {
            throw ValidationException::withMessages(['password' => __('Enter the mailbox password.')]);
        }

        $this->assertConnects($this->provider->testSettings(
            $request->imapSettings(),
            $request->smtpSettings(),
            $password,
            is_string($smtpPassword) ? $smtpPassword : null,
        ));

        $account->forceFill([
            'display_name' => $request->validated('name') ?? $account->display_name,
            'settings' => array_merge($account->settings ?? [], ['imap' => $request->imapSettings(), 'smtp' => $request->smtpSettings()]),
            'credentials' => array_filter(array_merge($account->credentials ?? [], ['password' => $password, 'smtp_password' => $smtpPassword])),
            'status' => MailAccountStatus::Connected,
            'last_error' => null,
            'error_count' => 0,
        ])->save();

        SyncMailAccount::dispatch($account, SyncRunType::Incremental);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mailbox settings updated.')]);

        return back();
    }

    /**
     * Disconnect any provider's account; a personal inbox left without accounts is removed too.
     */
    public function destroy(Request $request, MailAccount $account): RedirectResponse
    {
        $this->authorizeManage($request->user(), $account);

        try {
            $this->providers->for($account)->disconnect($account);
        } catch (Throwable $e) {
            Log::warning('Provider disconnect failed; removing the account anyway', ['account_id' => $account->id, 'error' => $e->getMessage()]);
        }

        $inbox = $account->inbox;

        DB::transaction(function () use ($account, $inbox) {
            $account->forceFill(['status' => MailAccountStatus::Disconnected])->save();
            $account->delete();

            if ($inbox->isPersonal() && ! $inbox->mailAccounts()->exists()) {
                $inbox->delete();
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mailbox :email disconnected.', ['email' => $account->email_address])]);

        return back();
    }

    protected function resolveInbox(StoreImapAccountRequest $request, User $user): ?Inbox
    {
        $uuid = $request->validated('inbox');

        if (! $uuid) {
            return null;
        }

        $inbox = Inbox::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($inbox->roleFor($user) === InboxRole::Admin, 403);

        return $inbox;
    }

    protected function assertConnects(ConnectionTestResult $result): void
    {
        if ($result->ok) {
            return;
        }

        $field = ($result->details['stage'] ?? 'imap') === 'smtp' ? 'smtp_host' : 'host';

        throw ValidationException::withMessages([$field => $result->message ?? __('Connection failed.')]);
    }

    protected function authorizeManage(User $user, MailAccount $account): void
    {
        $owns = $account->user_id === $user->id;
        $administers = $account->inbox->roleFor($user) === InboxRole::Admin;

        abort_unless($owns || $administers, 403);
    }
}
