<?php

namespace App\Jobs\Calls;

use App\Enums\Calls\DeliveryStatus;
use App\Mail\Calls\CallTranscriptMail;
use App\Models\Calls\CallTranscriptDelivery;
use App\Services\Calls\CallMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one transcript email and records what happened to it.
 */
class SendCallTranscript implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 180];

    public function __construct(public CallTranscriptDelivery $delivery)
    {
        $this->onQueue('notifications');
    }

    public function handle(CallMailer $mailer): void
    {
        $delivery = $this->delivery;

        if ($delivery->status === DeliveryStatus::Sent) {
            return;
        }

        $mailer->sendNow(
            $delivery->call->organization,
            $delivery->email,
            new CallTranscriptMail($delivery->call, $delivery->include_files, $delivery->include_recording),
        );

        $delivery->forceFill(['status' => DeliveryStatus::Sent, 'sent_at' => now(), 'error' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->delivery->forceFill([
            'status' => DeliveryStatus::Failed,
            'error' => Str::limit($exception?->getMessage() ?? 'Delivery failed.', 900, ''),
        ])->save();
    }
}
