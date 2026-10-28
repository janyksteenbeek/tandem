<?php

namespace App\Console\Commands\Mail;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Enums\SyncRunType;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\MailAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('mail:gmail:reconcile')]
#[Description('Queue an incremental sync for healthy Gmail accounts that have not synced recently')]
class ReconcileGmailAccountsCommand extends Command
{
    public function handle(): int
    {
        $staleBefore = now()->subMinutes((int) config('tandem.gmail.reconcile_minutes', 15));
        $dispatched = 0;

        MailAccount::query()
            ->where('provider', MailProvider::Gmail)
            ->whereIn('status', [MailAccountStatus::Connected, MailAccountStatus::Syncing])
            ->whereNotNull('initial_sync_completed_at')
            ->where(fn (Builder $query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $staleBefore))
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$dispatched) {
                foreach ($accounts as $account) {
                    SyncMailAccount::dispatch($account, SyncRunType::Incremental);
                    $dispatched++;
                }
            });

        $this->info(sprintf('Queued %d Gmail reconcile sync(s).', $dispatched));

        return self::SUCCESS;
    }
}
