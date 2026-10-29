<?php

namespace App\Services\Mail\Providers\Forwarding;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider as ProviderType;
use App\Models\MailAccount;
use App\Models\SyncRun;
use App\Services\Mail\Data\ConnectionTestResult;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Data\SentMessageResult;
use App\Services\Mail\Data\SyncResult;
use App\Services\Mail\Providers\AbstractMailProvider;

/**
 * Push-only adapter shared by the Forward and Google Group channels: mail arrives through
 * the Cloudflare Email Worker webhook (CloudflareInboundController -> IngestForwardedEmail).
 * There is nothing to poll and nothing to send; outbound replies use the FallbackMailer.
 */
class ForwardingProvider extends AbstractMailProvider
{
    public function type(): ProviderType
    {
        return ProviderType::Forwarding;
    }

    public function testConnection(MailAccount $account): ConnectionTestResult
    {
        $lastReceivedAt = $account->last_synced_at;

        return ConnectionTestResult::success(
            $lastReceivedAt !== null
                ? sprintf('%s address %s is active; last message received %s.', $account->provider->label(), $account->email_address, $lastReceivedAt->diffForHumans())
                : sprintf('%s address %s is ready but has not received any mail yet.', $account->provider->label(), $account->email_address),
            [
                'address' => $account->email_address,
                'has_received_mail' => $lastReceivedAt !== null,
                'last_received_at' => $lastReceivedAt?->toIso8601String(),
                'received_count' => (int) $account->syncState('received_count', 0),
            ],
        );
    }

    public function initialSync(MailAccount $account, SyncRun $run): SyncResult
    {
        return $this->pushOnly($account, $run);
    }

    public function incrementalSync(MailAccount $account, SyncRun $run): SyncResult
    {
        return $this->pushOnly($account, $run);
    }

    public function send(MailAccount $account, OutgoingMessage $message): SentMessageResult
    {
        $this->unsupported('sending; use the fallback mailer');
    }

    public function disconnect(MailAccount $account): void
    {
        $account->forceFill(['status' => MailAccountStatus::Disconnected])->save();
    }

    protected function pushOnly(MailAccount $account, SyncRun $run): SyncResult
    {
        $run->forceFill(['stats' => array_merge($run->stats ?? [], ['mode' => 'push'])])->save();

        if ($account->initial_sync_completed_at === null) {
            $account->forceFill(['initial_sync_completed_at' => now()])->save();
        }

        return new SyncResult;
    }
}
