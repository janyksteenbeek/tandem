<?php

namespace App\Console\Commands;

use App\Models\Automations\AutomationRun;
use App\Models\Mail\SyncRun;
use App\Models\Projects\Issue;
use App\Models\Projects\RecentView;
use App\Models\WebhookReceipt;
use Illuminate\Console\Command;

class PruneOperationalDataCommand extends Command
{
    protected $signature = 'tandem:prune {--sync-days=14} {--webhook-days=30} {--automation-days=90} {--recent-days=60} {--trash-days=30}';

    protected $description = 'Delete old sync runs, webhook receipts, automation runs, recent views and trashed issues';

    public function handle(): int
    {
        $syncRuns = SyncRun::query()->where('started_at', '<', now()->subDays((int) $this->option('sync-days')))->delete();
        $receipts = WebhookReceipt::query()->where('received_at', '<', now()->subDays((int) $this->option('webhook-days')))->whereNotNull('processed_at')->delete();
        $automationRuns = AutomationRun::query()->where('created_at', '<', now()->subDays((int) $this->option('automation-days')))->delete();
        $recentViews = RecentView::query()->where('viewed_at', '<', now()->subDays((int) $this->option('recent-days')))->delete();
        $trashedIssues = Issue::onlyTrashed()->where('deleted_at', '<', now()->subDays((int) $this->option('trash-days')))->forceDelete();

        $this->info("Pruned {$syncRuns} sync runs, {$receipts} webhook receipts, {$automationRuns} automation runs, {$recentViews} recent views and {$trashedIssues} trashed issues.");

        return self::SUCCESS;
    }
}
