<?php

namespace App\Jobs\Mail;

use App\Enums\ConversationEventType;
use App\Enums\DeliveryStatus;
use App\Enums\MailProvider;
use App\Enums\ProviderCapability;
use App\Events\Mail\MessageSent;
use App\Models\Message;
use App\Services\Mail\Data\Address;
use App\Services\Mail\Data\OutgoingAttachment;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use App\Services\Mail\Exceptions\RateLimitedException;
use App\Services\Mail\MailProviderManager;
use App\Services\Mail\Outbound\FallbackMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one outbound message. Unique per message so a redispatch never double-sends,
 * and it checks the stored delivery state before every attempt.
 */
class SendOutgoingMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 600;

    public function __construct(public Message $message)
    {
        $this->onQueue('mail-outbound');
    }

    public function uniqueId(): string
    {
        return (string) $this->message->id;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(MailProviderManager $providers, FallbackMailer $fallback): void
    {
        $message = $this->message->fresh(['conversation', 'mailAccount', 'attachments']);

        if (! $message || $message->delivery_status === DeliveryStatus::Sent) {
            return;
        }

        $message->forceFill([
            'delivery_status' => DeliveryStatus::Sending,
            'send_attempts' => $message->send_attempts + 1,
        ])->save();

        try {
            $outgoing = $this->buildOutgoing($message);
            $account = $message->mailAccount;

            $result = $account && $account->supports(ProviderCapability::Send) && $account->provider !== MailProvider::Forwarding
                ? $providers->for($account)->send($account, $outgoing)
                : $fallback->send($account, $outgoing);

            $message->forceFill([
                'delivery_status' => DeliveryStatus::Sent,
                'delivery_error' => null,
                'message_id_header' => $result->messageId,
                'message_id_hash' => Message::hashMessageId($result->messageId),
                'provider_message_id' => $result->providerMessageId ?? $message->provider_message_id,
                'provider_thread_id' => $result->providerThreadId ?? $message->provider_thread_id,
                'sent_at' => now(),
            ])->save();

            $conversation = $message->conversation;

            if ($result->providerThreadId && ! $conversation->provider_thread_id) {
                $conversation->forceFill(['provider_thread_id' => $result->providerThreadId])->save();
            }

            $conversation->refreshCounters();

            MessageSent::dispatch($message->fresh());
        } catch (AuthenticationRequiredException $e) {
            $this->failPermanently($message, $e, 'The mail account needs to be reconnected before Tandem can send from it.');
        } catch (RateLimitedException $e) {
            $message->forceFill(['delivery_status' => DeliveryStatus::Queued, 'delivery_error' => $e->getMessage()])->save();
            $this->release($e->retryAfterSeconds);
        } catch (Throwable $e) {
            Log::warning('Outbound send failed', ['message_id' => $message->id, 'attempt' => $this->attempts(), 'error' => $e->getMessage()]);

            if ($this->attempts() >= $this->tries) {
                $this->failPermanently($message, $e);

                return;
            }

            $message->forceFill(['delivery_status' => DeliveryStatus::Queued, 'delivery_error' => $e->getMessage()])->save();

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = $this->message->fresh();

        if ($message && $message->delivery_status !== DeliveryStatus::Sent) {
            $this->failPermanently($message, $exception);
        }
    }

    protected function failPermanently(Message $message, ?Throwable $exception, ?string $hint = null): void
    {
        $message->forceFill([
            'delivery_status' => DeliveryStatus::Failed,
            'delivery_error' => $hint ?? $exception?->getMessage() ?? 'Unknown error',
        ])->save();

        $message->conversation->events()->create([
            'type' => ConversationEventType::MessageFailed,
            'actor_id' => $message->author_id,
            'payload' => ['message_id' => $message->id, 'error' => $message->delivery_error],
        ]);

        MessageSent::dispatch($message->fresh());
    }

    protected function buildOutgoing(Message $message): OutgoingMessage
    {
        $from = Address::fromArray($message->from) ?? new Address($message->mailAccount->email_address ?? config('mail.from.address'));

        return new OutgoingMessage(
            from: $from,
            to: Address::fromArrayList($message->to),
            subject: $message->subject ?? '',
            htmlBody: $message->html_body ?? '',
            textBody: $message->text_body,
            cc: Address::fromArrayList($message->cc),
            bcc: Address::fromArrayList($message->bcc),
            replyTo: Address::fromArrayList($message->reply_to)[0] ?? null,
            messageId: $message->message_id_header,
            inReplyTo: $message->in_reply_to,
            references: $message->references ?? [],
            attachments: $message->attachments->map(fn ($attachment) => new OutgoingAttachment(
                filename: $attachment->filename,
                mimeType: $attachment->mime_type,
                disk: $attachment->disk,
                path: (string) $attachment->path,
                contentId: $attachment->content_id,
                isInline: $attachment->is_inline,
            ))->all(),
            headers: ['X-Tandem-Message' => $message->uuid],
            providerThreadId: $message->conversation->provider_thread_id,
        );
    }
}
