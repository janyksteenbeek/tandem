<?php

namespace App\Console\Commands\Mail;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Enums\SyncRunType;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\MailAccount;
use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use App\Services\Mail\Providers\Imap\ImapClientFactory;
use App\Services\Mail\Providers\Imap\ImapSession;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Long-running IMAP IDLE listener. Keeps a connection open on INBOX and queues an incremental
 * sync the moment the server announces a change. Run one process per account under Horizon or
 * Supervisor for instant delivery; mail:imap:poll remains the safety net.
 */
class ImapIdleCommand extends Command
{
    protected $signature = 'mail:imap:idle
        {account? : Mail account uuid; omitted = round-robin over all connected IMAP accounts}
        {--timeout=1740 : Seconds to stay in IDLE before re-issuing the command (RFC 2177 recommends < 29 minutes)}
        {--once : Run a single IDLE cycle and exit (useful for tests and health checks)}';

    protected $description = 'Listen for new mail on IMAP accounts using IDLE and queue syncs instantly';

    protected bool $shouldStop = false;

    public function handle(ImapClientFactory $clients): int
    {
        $this->trapSignals();

        $timeout = max(30, min(1740, (int) $this->option('timeout')));
        $once = (bool) $this->option('once');
        $failures = [];

        while (! $this->shouldStop) {
            $accounts = $this->accounts();

            if ($accounts->isEmpty()) {
                $this->warn('No connected IMAP accounts to watch.');

                if ($once) {
                    return self::SUCCESS;
                }

                $this->pause(30);

                continue;
            }

            $slice = $accounts->count() === 1 ? $timeout : max(30, intdiv($timeout, $accounts->count()));

            foreach ($accounts as $account) {
                if ($this->shouldStop) {
                    break;
                }

                try {
                    $changed = $this->idle($clients, $account, $slice);
                    $failures[$account->id] = 0;

                    if ($changed) {
                        SyncMailAccount::dispatch($account, SyncRunType::Incremental);
                        $this->line(sprintf('[%s] %s: mailbox changed, sync queued.', now()->toTimeString(), $account->email_address));
                    }
                } catch (AuthenticationRequiredException $e) {
                    $account->markSyncFailed($e->getMessage(), needsReauth: true);
                    $this->error(sprintf('%s: %s', $account->email_address, $e->getMessage()));
                } catch (Throwable $e) {
                    $failures[$account->id] = ($failures[$account->id] ?? 0) + 1;
                    $delay = min(300, 5 * 2 ** min(6, $failures[$account->id] - 1));
                    $this->warn(sprintf('%s: %s (retrying in %ds)', $account->email_address, $e->getMessage(), $delay));
                    $this->pause($delay);
                }
            }

            if ($once) {
                break;
            }
        }

        $this->info('IDLE listener stopped.');

        return self::SUCCESS;
    }

    /**
     * Returns true when the server reported a change while idling.
     */
    protected function idle(ImapClientFactory $clients, MailAccount $account, int $timeout): bool
    {
        $session = $clients->make($account);

        try {
            return $this->idleOn($session, $account, $timeout);
        } finally {
            $session->close();
        }
    }

    protected function idleOn(ImapSession $session, MailAccount $account, int $timeout): bool
    {
        $inbox = $account->folders()->where('type', 'inbox')->value('provider_id') ?? 'INBOX';

        return $session->idle((string) $inbox, $timeout, fn (): bool => $this->shouldStop);
    }

    /**
     * @return Collection<int, MailAccount>
     */
    protected function accounts(): Collection
    {
        $query = MailAccount::query()
            ->where('provider', MailProvider::Imap)
            ->whereIn('status', [MailAccountStatus::Connected, MailAccountStatus::Syncing, MailAccountStatus::Error])
            ->whereNotNull('initial_sync_completed_at');

        if ($uuid = $this->argument('account')) {
            $query->where('uuid', $uuid);
        }

        return $query->orderBy('id')->get();
    }

    protected function trapSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () {
                $this->shouldStop = true;
                $this->line('Stopping after the current IDLE cycle...');
            });
        }
    }

    protected function pause(int $seconds): void
    {
        for ($i = 0; $i < $seconds && ! $this->shouldStop; $i++) {
            sleep(1);
        }
    }
}
