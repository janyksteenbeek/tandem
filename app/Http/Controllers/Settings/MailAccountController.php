<?php

namespace App\Http\Controllers\Settings;

use App\Enums\InboxType;
use App\Enums\MailAccountStatus;
use App\Enums\SyncRunType;
use App\Http\Controllers\Controller;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\SyncRun;
use App\Services\Mail\MailProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Inertia\Inertia;
use Inertia\Response;

class MailAccountController extends Controller
{
    /**
     * Personal accounts of the user plus shared inboxes they administer, with connection health.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = $user->currentOrganization;

        $personal = $user->mailAccounts()->with(['inbox', 'identities'])->orderBy('created_at')->get();

        $shared = $organization
            ? Inbox::query()->accessibleBy($user)
                ->where('organization_id', $organization->id)
                ->where('type', InboxType::Shared->value)
                ->with(['mailAccounts.identities'])
                ->orderBy('position')->orderBy('name')
                ->get()
                ->filter(fn (Inbox $inbox) => $user->can('manage', $inbox))
                ->values()
            : collect();

        return Inertia::render('settings/MailAccounts', [
            'personalAccounts' => $personal->map(fn (MailAccount $account) => $this->accountPayload($account))->values(),
            'sharedInboxes' => $shared->map(fn (Inbox $inbox) => [
                'uuid' => $inbox->uuid,
                'name' => $inbox->name,
                'color' => $inbox->color,
                'email_address' => $inbox->email_address,
                'accounts' => $inbox->mailAccounts->map(fn (MailAccount $account) => $this->accountPayload($account))->values(),
            ])->values(),
            'forwardingDomain' => config('tandem.forwarding.domain'),
            'googleConfigured' => (bool) ($organization?->hasGoogleOAuthCredentials() || config('services.google.client_id')),
        ]);
    }

    /**
     * Connection health details for one account: recent sync runs and queue depth.
     */
    public function health(Request $request, MailAccount $account): JsonResponse
    {
        $this->authorize('view', $account);

        $runs = $account->syncRuns()->latest('started_at')->limit(15)->get()->map(fn (SyncRun $run) => [
            'id' => $run->id,
            'type' => $run->type->value,
            'status' => $run->status->value,
            'started_at' => $run->started_at->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'duration_ms' => $run->finished_at ? $run->started_at->diffInMilliseconds($run->finished_at) : null,
            'stats' => $run->stats,
            'error' => $run->error,
        ]);

        $pending = 0;

        try {
            $pending = Queue::connection(config('queue.default'))->size('mail-sync');
        } catch (\Throwable) {
            $pending = 0;
        }

        return response()->json([
            'account' => $this->accountPayload($account->fresh(['inbox', 'identities'])),
            'runs' => $runs,
            'queue' => ['pending' => $pending],
            'folders' => $account->folders()->orderBy('name')->get()->map(fn ($folder) => [
                'id' => $folder->id,
                'name' => $folder->name,
                'type' => $folder->type->value,
                'sync_enabled' => $folder->sync_enabled,
                'last_synced_at' => $folder->last_synced_at?->toISOString(),
                'message_count' => $folder->message_count,
            ]),
        ]);
    }

    public function sync(Request $request, MailAccount $account): JsonResponse
    {
        $this->authorize('view', $account);

        abort_if($account->status === MailAccountStatus::NeedsReauth, 422, 'Reconnect the account before syncing.');

        $type = $account->initial_sync_completed_at ? SyncRunType::Incremental : SyncRunType::Initial;
        SyncMailAccount::dispatch($account, $type);

        return response()->json(['queued' => true, 'type' => $type->value]);
    }

    public function test(Request $request, MailAccount $account, MailProviderManager $providers): JsonResponse
    {
        $this->authorize('view', $account);

        $result = $providers->for($account)->testConnection($account);

        return response()->json(['ok' => $result->ok, 'message' => $result->message, 'details' => $result->details]);
    }

    public function disconnect(Request $request, MailAccount $account, MailProviderManager $providers): RedirectResponse
    {
        $this->authorize('manage', $account);

        try {
            $providers->for($account)->disconnect($account);
        } catch (\Throwable) {
            // The remote side may already be gone; local state is what matters.
        }

        $account->forceFill(['status' => MailAccountStatus::Disconnected])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account disconnected. Mail stays available in Tandem.')]);

        return back();
    }

    public function destroy(Request $request, MailAccount $account, MailProviderManager $providers): RedirectResponse
    {
        $this->authorize('manage', $account);

        try {
            $providers->for($account)->disconnect($account);
        } catch (\Throwable) {
            //
        }

        $inbox = $account->inbox;
        $account->delete();

        if ($inbox->isPersonal() && $inbox->mailAccounts()->count() === 0) {
            $inbox->delete();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account removed.')]);

        return to_route('mail.accounts.index');
    }

    public function updateDefaultInbox(Request $request): RedirectResponse
    {
        $data = $request->validate(['inbox' => ['required', 'string', 'exists:inboxes,uuid']]);

        $inbox = Inbox::query()->where('uuid', $data['inbox'])->firstOrFail();
        $this->authorize('view', $inbox);

        $user = $request->user();
        $user->forceFill(['preferences' => array_merge($user->preferences ?? [], ['default_inbox_uuid' => $inbox->uuid])])->save();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function accountPayload(MailAccount $account): array
    {
        return [
            'uuid' => $account->uuid,
            'provider' => $account->provider->value,
            'provider_label' => $account->provider->label(),
            'email' => $account->email_address,
            'display_name' => $account->display_name,
            'status' => $account->status->value,
            'status_label' => $account->status->label(),
            'healthy' => $account->status->isHealthy(),
            'last_synced_at' => $account->last_synced_at?->toISOString(),
            'last_sync_started_at' => $account->last_sync_started_at?->toISOString(),
            'initial_sync_completed_at' => $account->initial_sync_completed_at?->toISOString(),
            'watch_expires_at' => $account->watch_expires_at?->toISOString(),
            'last_error' => $account->last_error,
            'error_count' => $account->error_count,
            'inbox' => ['uuid' => $account->inbox->uuid, 'name' => $account->inbox->name, 'type' => $account->inbox->type->value],
            'settings' => collect($account->settings ?? [])->except(['password'])->all(),
            'identities' => $account->identities->map(fn ($identity) => [
                'id' => $identity->id,
                'name' => $identity->name,
                'email' => $identity->email,
                'reply_to' => $identity->reply_to,
                'signature_html' => $identity->signature_html,
                'is_default' => $identity->is_default,
            ])->values(),
            'received_count' => $account->syncState('received_count', 0),
            'inbound_token_set' => $account->inbound_token !== null,
        ];
    }
}
