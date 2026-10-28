<?php

namespace App\Jobs\Mail;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Http\Controllers\Webhooks\CloudflareInboundController;
use App\Models\MailAccount;
use App\Models\WebhookReceipt;
use App\Services\Mail\Data\Address;
use App\Services\Mail\Data\IncomingMessage;
use App\Services\Mail\Data\MailboxState;
use App\Services\Mail\Ingestion\MessageIngestor;
use App\Services\Mail\Ingestion\MimeMessageParser;
use App\Services\Mail\Providers\Forwarding\ForwardingAddressGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Parses and ingests one message that the Cloudflare Email Worker delivered. The raw MIME is
 * kept on disk until ingestion succeeds so a crash never loses mail; after the final failed
 * attempt the file stays behind for manual recovery.
 */
class IngestForwardedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_HOPS = 3;

    public int $tries = 5;

    public function __construct(
        public int $mailAccountId,
        public int $receiptId,
        public string $path,
    ) {
        $this->onQueue('mail-inbound');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(MimeMessageParser $parser, MessageIngestor $ingestor, ForwardingAddressGenerator $addresses): void
    {
        $receipt = WebhookReceipt::query()->find($this->receiptId);

        if ($receipt === null || in_array($receipt->status, ['processed', 'skipped'], true)) {
            return;
        }

        $account = MailAccount::query()->find($this->mailAccountId);

        if ($account === null || $account->provider !== MailProvider::Forwarding) {
            $this->skip($receipt, 'account_missing');

            return;
        }

        $disk = Storage::disk(CloudflareInboundController::DISK);

        if (! $disk->exists($this->path)) {
            $this->recordFailure($receipt, 'Raw message file is missing: '.$this->path, final: true);

            return;
        }

        $raw = (string) $disk->get($this->path);
        $payload = $receipt->payload ?? [];

        try {
            if ((int) ($payload['hops'] ?? 0) > self::MAX_HOPS) {
                $this->skip($receipt, 'too_many_hops');

                return;
            }

            $incoming = $parser->parse($raw, [
                'state' => MailboxState::inbox(),
                'receivedAt' => $this->receivedAt($payload),
                'labels' => ['INBOX'],
            ]);

            $incoming = $incoming->with([
                'providerMessageId' => 'fwd:'.sha1($incoming->messageId ?: $raw),
            ]);

            if ($reason = $this->loopReason($account, $incoming, $addresses)) {
                $this->skip($receipt, $reason, $incoming);

                return;
            }

            $result = $ingestor->ingest($account, $incoming);

            $account->forceFill([
                'status' => MailAccountStatus::Connected,
                'last_synced_at' => now(),
                'last_error' => null,
                'error_count' => 0,
            ])->save();

            $account->mergeSyncState([
                'received_count' => (int) $account->syncState('received_count', 0) + 1,
                'last_received_at' => now()->toIso8601String(),
            ]);

            $receipt->update([
                'status' => 'processed',
                'processed_at' => now(),
                'payload' => array_merge($payload, [
                    'message_id' => $result->message->id,
                    'conversation_id' => $result->conversation->id,
                    'created' => $result->created,
                ]),
            ]);

            $disk->delete($this->path);
        } catch (Throwable $e) {
            $this->recordFailure($receipt, $e->getMessage(), final: $this->attempts() >= $this->tries);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $receipt = WebhookReceipt::query()->find($this->receiptId);

        if ($receipt !== null && $receipt->status !== 'processed') {
            $this->recordFailure($receipt, $exception?->getMessage() ?? 'Unknown error', final: true);
        }

        MailAccount::query()->find($this->mailAccountId)?->markSyncFailed($exception?->getMessage() ?? 'Forwarded message could not be ingested.');
    }

    /**
     * Detect messages that would bounce between Tandem and the forwarding domain forever.
     */
    protected function loopReason(MailAccount $account, IncomingMessage $incoming, ForwardingAddressGenerator $addresses): ?string
    {
        if ($incoming->header('x-tandem-message') !== null) {
            return 'own_outbound_message';
        }

        $own = $this->organizationForwardingAddresses($account, $addresses);

        if ($incoming->from && in_array($addresses->normalizeRecipient($incoming->from->email), $own, true)) {
            return 'from_forwarding_address';
        }

        if ($incoming->isAutoSubmitted()) {
            $recipients = collect($incoming->allRecipients())->map(fn (Address $address) => $addresses->normalizeRecipient($address->email));

            if ($recipients->isNotEmpty() && $recipients->every(fn (string $email) => in_array($email, $own, true))) {
                return 'auto_submitted_to_forwarding_address';
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function organizationForwardingAddresses(MailAccount $account, ForwardingAddressGenerator $addresses): array
    {
        $query = MailAccount::withTrashed()->where('provider', MailProvider::Forwarding);

        if ($account->organization_id !== null) {
            $query->where('organization_id', $account->organization_id);
        } else {
            $query->whereKey($account->id);
        }

        return $query->pluck('email_address')
            ->map(fn (string $email) => $addresses->normalizeRecipient($email))
            ->push($addresses->normalizeRecipient($account->email_address))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function receivedAt(array $payload): CarbonImmutable
    {
        try {
            return isset($payload['received_at']) && is_string($payload['received_at'])
                ? CarbonImmutable::parse($payload['received_at'])->utc()
                : CarbonImmutable::now();
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }

    protected function skip(WebhookReceipt $receipt, string $reason, ?IncomingMessage $incoming = null): void
    {
        Log::info('Skipped forwarded message.', [
            'receipt_id' => $receipt->id,
            'mail_account_id' => $this->mailAccountId,
            'reason' => $reason,
            'message_id' => $incoming?->messageId,
            'from' => $incoming?->from?->email,
        ]);

        $receipt->update([
            'status' => 'skipped',
            'processed_at' => now(),
            'payload' => array_merge($receipt->payload ?? [], ['skipped_reason' => $reason]),
        ]);

        Storage::disk(CloudflareInboundController::DISK)->delete($this->path);
    }

    protected function recordFailure(WebhookReceipt $receipt, string $error, bool $final): void
    {
        Log::warning('Forwarded message ingestion failed.', [
            'receipt_id' => $receipt->id,
            'mail_account_id' => $this->mailAccountId,
            'attempt' => $this->attempts(),
            'final' => $final,
            'error' => $error,
        ]);

        $receipt->update([
            'status' => 'failed',
            'payload' => array_merge($receipt->payload ?? [], [
                'error' => Str::limit($error, 1000),
                'attempts' => $this->attempts(),
                'final' => $final,
            ]),
        ]);
    }
}
