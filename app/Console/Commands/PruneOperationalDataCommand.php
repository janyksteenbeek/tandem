<?php

namespace App\Console\Commands;

use App\Models\AutomationRun;
use App\Models\SyncRun;
use App\Models\WebhookReceipt;
use Illuminate\Console\Command;

class PruneOperationalDataCommand extends Command
{
    protected $signature = 'tandem:prune {--sync-days=14} {--webhook-days=30} {--automation-days=90}';

    protected $description = 'Delete old sync runs, webhook receipts and automation runs';

    public function handle(): int
    {
        $syncRuns = SyncRun::query()->where('started_at', '<', now()->subDays((int) $this->option('sync-days')))->delete();
        $receipts = WebhookReceipt::query()->where('received_at', '<', now()->subDays((int) $this->option('webhook-days')))->whereNotNull('processed_at')->delete();
        $automationRuns = AutomationRun::query()->where('created_at', '<', now()->subDays((int) $this->option('automation-days')))->delete();

        $this->info("Pruned {$syncRuns} sync runs, {$receipts} webhook receipts and {$automationRuns} automation runs.");

        return self::SUCCESS;
    }
}
