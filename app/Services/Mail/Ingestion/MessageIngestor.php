<?php

namespace App\Services\Mail\Ingestion;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Events\Mail\MessageIngested;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MailAccount;
use App\Models\Message;
use App\Services\Mail\Data\Address;
use App\Services\Mail\Data\IncomingMessage;
use App\Services\Mail\Data\MailboxState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single write path for mail entering Tandem. Idempotent on provider id and Message-ID,
 * transactional per message, and tolerant of out-of-order delivery.
 */
class MessageIngestor
{
    public function __construct(
        protected ThreadResolver $threads,
        protected HtmlSanitizer $sanitizer,
        protected AttachmentStore $attachments,
    ) {}

    public function ingest(MailAccount $account, IncomingMessage $incoming): IngestResult
    {
        $existing = $this->findExisting($account, $incoming);

        if ($existing) {
            return $this->refresh($existing, $incoming);
        }

        $result = DB::transaction(function () use ($account, $incoming) {
            $conversation = $this->threads->resolve($account, $incoming);
            $createdConversation = false;
            $reopened = false;

            if ($conversation) {
                $conversation = Conversation::withTrashed()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

                if ($conversation->trashed()) {
                    $conversation->restore();
                }
            } else {
                $conversation = $this->createConversation($account, $incoming);
                $createdConversation = true;
            }

            // Re-check under the lock: a concurrent job may have stored the same message meanwhile.
            if ($duplicate = $this->findExisting($account, $incoming)) {
                return new IngestResult($duplicate, $duplicate->conversation, false, false);
            }

            $direction = $this->direction($account, $incoming);
            $message = $this->storeMessage($account, $conversation, $incoming, $direction);

            foreach ($incoming->attachments as $attachment) {
                $this->attachments->store($message, $attachment);
            }

            if ($incoming->rawMime !== null) {
                $message->forceFill(['raw_path' => $this->attachments->storeRawMime($message, $incoming->rawMime)])->save();
            }

            if ($direction === MessageDirection::Inbound && ! $createdConversation) {
                $reopened = $this->reopenIfNeeded($conversation, $message);
            }

            $this->touchConversation($conversation, $message, $incoming);
            $this->touchContacts($account, $incoming);

            $conversation->events()->create([
                'type' => $direction === MessageDirection::Inbound ? ConversationEventType::MessageReceived : ConversationEventType::MessageSent,
                'payload' => ['message_id' => $message->id, 'from' => $incoming->from?->toArray()],
                'created_at' => $incoming->sentAt,
            ]);

            $result = new IngestResult($message, $conversation->refresh(), true, $createdConversation);

            DB::afterCommit(fn () => MessageIngested::dispatch($message, $result->conversation, $createdConversation, $reopened));

            return $result;
        });

        return $result;
    }

    protected function findExisting(MailAccount $account, IncomingMessage $incoming): ?Message
    {
        $query = Message::withTrashed()->where('mail_account_id', $account->id);

        if ($incoming->providerMessageId) {
            $found = (clone $query)->where('provider_message_id', $incoming->providerMessageId)->first();

            if ($found) {
                return $found;
            }
        }

        if ($incoming->messageId) {
            return (clone $query)->where('message_id_hash', Message::hashMessageId($incoming->messageId))->first();
        }

        return null;
    }

    /**
     * Update mutable provider state (flags, labels, folder) for a message we already have.
     */
    protected function refresh(Message $message, IncomingMessage $incoming): IngestResult
    {
        $state = $incoming->state();

        $message->forceFill(array_filter([
            'provider_message_id' => $message->provider_message_id ?? $incoming->providerMessageId,
            'provider_thread_id' => $message->provider_thread_id ?? $incoming->providerThreadId,
            'imap_uid' => $incoming->imapUid ?? $message->imap_uid,
            'folder_id' => $incoming->folderId ?? $message->folder_id,
        ], fn ($value) => $value !== null));

        $message->provider_labels = $incoming->labels ?: $message->provider_labels;
        $message->mailbox_state = $state->toArray();
        $message->is_read = $state->isRead;
        $message->is_starred = $state->isStarred;

        if ($message->trashed() && ! $state->isTrashed) {
            $message->restore();
        }

        $message->save();

        $conversation = $message->conversation()->withTrashed()->first();

        if ($conversation) {
            $this->recomputeFlags($conversation);
            $conversation->refreshCounters();
        }

        return new IngestResult($message, $conversation ?? $message->conversation, false, false);
    }

    protected function createConversation(MailAccount $account, IncomingMessage $incoming): Conversation
    {
        return Conversation::create([
            'organization_id' => $account->organization_id,
            'inbox_id' => $account->inbox_id,
            'mail_account_id' => $account->id,
            'subject' => $incoming->subject ?: '(no subject)',
            'status' => ConversationStatus::Open,
            'provider_thread_id' => $incoming->providerThreadId,
            'participants' => [],
            'first_message_at' => $incoming->sentAt,
            'last_message_at' => $incoming->sentAt,
            'last_activity_at' => $incoming->sentAt,
        ]);
    }

    protected function direction(MailAccount $account, IncomingMessage $incoming): MessageDirection
    {
        $own = collect([$account->email_address])
            ->merge($account->identities->pluck('email'))
            ->map(fn (string $email) => Str::lower($email));

        if ($incoming->from && $own->contains($incoming->from->email)) {
            return MessageDirection::Outbound;
        }

        return $incoming->state()->isSent ? MessageDirection::Outbound : MessageDirection::Inbound;
    }

    protected function storeMessage(MailAccount $account, Conversation $conversation, IncomingMessage $incoming, MessageDirection $direction): Message
    {
        $html = $this->sanitizer->sanitize($incoming->htmlBody);
        $text = $incoming->textBody ?? $this->sanitizer->toText($html);
        $state = $incoming->state();

        return $conversation->messages()->create([
            'mail_account_id' => $account->id,
            'folder_id' => $incoming->folderId,
            'direction' => $direction,
            'message_id_header' => $incoming->messageId,
            'in_reply_to' => $incoming->inReplyTo,
            'references' => $incoming->references,
            'provider_message_id' => $incoming->providerMessageId,
            'provider_thread_id' => $incoming->providerThreadId,
            'imap_uid' => $incoming->imapUid,
            'from' => $incoming->from?->toArray(),
            'to' => array_map(fn (Address $a) => $a->toArray(), $incoming->to),
            'cc' => array_map(fn (Address $a) => $a->toArray(), $incoming->cc),
            'bcc' => array_map(fn (Address $a) => $a->toArray(), $incoming->bcc),
            'reply_to' => array_map(fn (Address $a) => $a->toArray(), $incoming->replyTo),
            'subject' => $incoming->subject,
            'snippet' => $this->sanitizer->snippet($text, $html),
            'text_body' => $text,
            'html_body' => $html,
            'headers' => $incoming->headers,
            'provider_labels' => $incoming->labels,
            'mailbox_state' => $state->toArray(),
            'sent_at' => $incoming->sentAt,
            'received_at' => $incoming->receivedAt ?? now(),
            'is_read' => $direction === MessageDirection::Outbound || $state->isRead,
            'is_draft' => $state->isDraft,
            'is_starred' => $state->isStarred,
            'has_attachments' => collect($incoming->attachments)->contains(fn ($a) => ! $a->isInline),
            'size_bytes' => $incoming->sizeBytes,
            'delivery_status' => $direction === MessageDirection::Outbound ? DeliveryStatus::Sent : DeliveryStatus::None,
        ]);
    }

    protected function reopenIfNeeded(Conversation $conversation, Message $message): bool
    {
        if ($conversation->status === ConversationStatus::Open) {
            return false;
        }

        if ($conversation->last_message_at && $message->sent_at && $message->sent_at->lt($conversation->last_message_at)) {
            return false; // Out-of-order backfill of an older message must not reopen the thread.
        }

        $previous = $conversation->status;

        $conversation->forceFill([
            'status' => ConversationStatus::Open,
            'snoozed_until' => null,
            'closed_at' => null,
            'closed_by_id' => null,
        ])->save();

        $conversation->events()->create([
            'type' => ConversationEventType::StatusChanged,
            'payload' => ['from' => $previous->value, 'to' => ConversationStatus::Open->value, 'reason' => 'new_reply'],
        ]);

        return true;
    }

    protected function touchConversation(Conversation $conversation, Message $message, IncomingMessage $incoming): void
    {
        $participants = collect($conversation->participants ?? [])
            ->merge(collect([$incoming->from, ...$incoming->allRecipients()])->filter()->map(fn (Address $a) => $a->toArray()))
            ->unique('email')
            ->take(50)
            ->values()
            ->all();

        $isLatest = $conversation->last_message_at === null || ! $message->sent_at || $message->sent_at->gte($conversation->last_message_at);

        $conversation->forceFill([
            'participants' => $participants,
            'subject' => $conversation->subject && $conversation->subject !== '(no subject)' ? $conversation->subject : ($incoming->subject ?: '(no subject)'),
            'snippet' => $isLatest ? ($message->snippet ?? $conversation->snippet) : $conversation->snippet,
            'provider_thread_id' => $conversation->provider_thread_id ?? $incoming->providerThreadId,
            'last_activity_at' => max($conversation->last_activity_at ?? $message->sent_at, $message->sent_at),
        ])->save();

        $this->recomputeFlags($conversation);
        $conversation->refreshCounters();
    }

    /**
     * Aggregate per-message mailbox placement into conversation flags (Gmail thread semantics).
     */
    public function recomputeFlags(Conversation $conversation): void
    {
        $states = $conversation->messages()
            ->where('is_draft', false)
            ->pluck('mailbox_state')
            ->map(fn ($state) => is_array($state) ? $state : (new MailboxState)->toArray());

        if ($states->isEmpty()) {
            return;
        }

        $any = fn (string $key) => $states->contains(fn (array $s) => ($s[$key] ?? false) === true);
        $all = fn (string $key) => $states->every(fn (array $s) => ($s[$key] ?? false) === true);

        $trashed = $all('isTrashed');
        $spam = ! $trashed && $all('isSpam');
        $inInbox = ! $trashed && ! $spam && $any('inInbox');

        $conversation->forceFill([
            'in_inbox' => $inInbox,
            'is_trashed' => $trashed,
            'is_spam' => $spam,
            'is_archived' => ! $inInbox && ! $trashed && ! $spam,
            'is_starred' => $any('isStarred'),
        ])->save();
    }

    protected function touchContacts(MailAccount $account, IncomingMessage $incoming): void
    {
        $own = Str::lower($account->email_address);

        collect([$incoming->from, ...$incoming->allRecipients()])
            ->filter()
            ->reject(fn (Address $address) => $address->email === $own)
            ->unique(fn (Address $address) => $address->email)
            ->take(25)
            ->each(fn (Address $address) => Contact::touchAddress($account->organization_id, $account->user_id, $address->email, $address->name));
    }
}
