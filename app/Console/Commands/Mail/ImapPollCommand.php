<?php

namespace App\Console\Commands\Mail;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Enums\SyncRunType;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\MailAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fallback for servers without IDLE (and a safety net for those with it): queue an incremental
 * sync for every IMAP account that has not been synced within the configured interval.
 */
class ImapPollCommand extends Command
{
    protected $signature = 'mail:imap:poll {--minutes= : Override the poll interval from config}';

    protected $description = 'Queue incremental syncs for IMAP accounts that are due';

    public function handle(): int
    {
        $minutes = max(1, (int) ($this->option('minutes') ?: config('tandem.imap.poll_minutes', 2)));
        $threshold = now()->subMinutes($minutes);

        $due = MailAccount::query()
            ->where('provider', MailProvider::Imap)
            ->whereIn('status', [MailAccountStatus::Connected, MailAccountStatus::Syncing, MailAccountStatus::Error])
            ->where(fn (Builder $query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $threshold))
            ->where(fn (Builder $query) => $query->whereNull('last_sync_started_at')->orWhere('last_sync_started_at', '<', $threshold))
            ->get();

        $due->each(fn (MailAccount $account) => SyncMailAccount::dispatch($account, SyncRunType::Incremental));

        $this->info(sprintf('Queued %d IMAP sync(s).', $due->count()));

        return self::SUCCESS;
    }
}
