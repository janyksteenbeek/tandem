<?php

namespace App\Services\Mail\Providers\Gmail;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Models\MailAccount;
use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use App\Services\Mail\Exceptions\ProviderException;
use Carbon\CarbonImmutable;
use Google\Service\Gmail;
use Google\Service\Gmail\WatchRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registers and renews Gmail push notifications (users.watch). Google expires a watch after
 * seven days, so the scheduler renews every watch well before that.
 */
class GmailWatchManager
{
    public function __construct(protected GmailClientFactory $clients) {}

    /**
     * Start (or extend) the push watch for the account's whole mailbox.
     */
    public function watch(MailAccount $account): void
    {
        $topic = $this->topicFor($account);

        if ($topic === null) {
            throw new ProviderException('No Google Pub/Sub topic is configured for Gmail push notifications.');
        }

        $request = new WatchRequest;
        $request->setTopicName($topic);

        $response = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users->watch('me', $request));

        $expiration = $response->getExpiration();

        $account->forceFill([
            'watch_expires_at' => $expiration ? CarbonImmutable::createFromTimestampMs((int) $expiration)->utc() : now()->addDays(7),
        ])->save();

        if ($response->getHistoryId() && ! $account->syncState('history_id')) {
            $account->mergeSyncState(['history_id' => (string) $response->getHistoryId()]);
        }
    }

    public function stop(MailAccount $account): void
    {
        $this->clients->call($account, fn (Gmail $gmail) => $gmail->users->stop('me'));

        $account->forceFill(['watch_expires_at' => null])->save();
    }

    /**
     * Renew every healthy Gmail watch that is missing or about to expire. Returns the number renewed.
     */
    public function renewExpiring(): int
    {
        $threshold = now()->addHours((int) config('tandem.gmail.watch_renew_before_hours', 24));
        $renewed = 0;

        MailAccount::query()
            ->where('provider', MailProvider::Gmail)
            ->whereIn('status', [MailAccountStatus::Connected, MailAccountStatus::Syncing])
            ->where(fn (Builder $query) => $query->whereNull('watch_expires_at')->orWhere('watch_expires_at', '<', $threshold))
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use (&$renewed) {
                foreach ($accounts as $account) {
                    if ($this->renew($account)) {
                        $renewed++;
                    }
                }
            });

        return $renewed;
    }

    protected function renew(MailAccount $account): bool
    {
        try {
            $this->watch($account);

            return true;
        } catch (AuthenticationRequiredException $e) {
            $account->markSyncFailed($e->getMessage(), needsReauth: true);
        } catch (Throwable $e) {
            Log::warning('Gmail watch renewal failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);
        }

        return false;
    }

    public function topicFor(MailAccount $account): ?string
    {
        $topic = $account->organization->google_pubsub_topic
            ?? $this->clients->oauthOrganizationFor($account)->google_pubsub_topic
            ?? config('services.google.pubsub_topic');

        return filled($topic) ? (string) $topic : null;
    }
}
