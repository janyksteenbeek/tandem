<?php

namespace App\Jobs\Mail;

use App\Enums\MailAccountStatus;
use App\Enums\SyncRunType;
use App\Models\MailAccount;
use App\Models\SyncRun;
use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use App\Services\Mail\Exceptions\RateLimitedException;
use App\Services\Mail\MailProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one sync pass (initial or incremental) for any provider. Unique while queued so a burst of
 * push notifications collapses into one job, and serialized per account so two passes never
 * overlap; a pass that has more work re-dispatches itself.
 */
class SyncMailAccount implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 600;

    public function __construct(public MailAccount $account, public SyncRunType $type = SyncRunType::Incremental)
    {
        $this->onQueue('mail-sync');
    }

    public function uniqueId(): string
    {
        return sprintf('%d:%s', $this->account->id, $this->type->value);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 90, 300, 900];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('mail-sync:'.$this->account->id))->releaseAfter(30)->expireAfter(900)];
    }

    public function handle(MailProviderManager $providers): void
    {
        $account = $this->account->fresh();

        if (! $account) {
            return;
        }

        $skipStatuses = [MailAccountStatus::Disconnected, MailAccountStatus::NeedsReauth];

        if ($this->type !== SyncRunType::Initial && in_array($account->status, $skipStatuses, true)) {
            return;
        }

        $type = $this->type === SyncRunType::Initial || $account->initial_sync_completed_at === null
            ? SyncRunType::Initial
            : SyncRunType::Incremental;

        $previousStatus = $account->status;
        $run = SyncRun::start($account, $type);
        $account->markSyncStarted();

        try {
            $provider = $providers->for($account);

            $result = $type === SyncRunType::Initial
                ? $provider->initialSync($account, $run)
                : $provider->incrementalSync($account, $run);

            $run->complete($result->toArray());
            $account->markSyncSucceeded();

            if ($result->hasMore) {
                static::dispatch($account, $this->type)->delay(now()->addSeconds(2));
            }
        } catch (AuthenticationRequiredException $e) {
            $account->markSyncFailed($e->getMessage(), needsReauth: true);
            $run->fail($e->getMessage());
            $this->fail($e);
        } catch (RateLimitedException $e) {
            $run->fail($e->getMessage());
            $account->forceFill(['status' => $previousStatus === MailAccountStatus::Syncing ? MailAccountStatus::Connected : $previousStatus])->save();
            $this->release(max(1, $e->retryAfterSeconds));
        } catch (Throwable $e) {
            Log::warning('Mail account sync failed', ['account_id' => $account->id, 'type' => $type->value, 'attempt' => $this->attempts(), 'error' => $e->getMessage()]);
            $account->markSyncFailed($e->getMessage());
            $run->fail($e->getMessage());

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $account = $this->account->fresh();

        if (! $account) {
            return;
        }

        if (in_array($account->status, [MailAccountStatus::NeedsReauth, MailAccountStatus::Disconnected, MailAccountStatus::Error], true)) {
            return;
        }

        $account->markSyncFailed($exception?->getMessage() ?? 'Sync failed permanently.');
    }
}
