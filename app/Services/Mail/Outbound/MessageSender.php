<?php

namespace App\Services\Mail\Outbound;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DraftKind;
use App\Enums\MessageDirection;
use App\Jobs\Mail\SendOutgoingMessage;
use App\Models\Conversation;
use App\Models\Draft;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\User;
use App\Services\Mail\Data\Address;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns a draft into a stored outbound message and queues delivery. The message row is the
 * source of truth: the UI shows "sending" immediately and delivery state flows back to it.
 */
class MessageSender
{
    public function __construct(
        protected HtmlSanitizer $sanitizer,
        protected FallbackMailer $fallback,
    ) {}

    /**
     * @param  array{close?: bool, status?: ConversationStatus|null}  $options
     */
    public function sendDraft(Draft $draft, User $user, array $options = []): Message
    {
        $draft->loadMissing(['conversation', 'inbox', 'mailAccount.identities', 'identity', 'attachments', 'inReplyTo']);

        $account = $draft->mailAccount ?? $this->defaultAccountFor($draft->inbox);
        $from = $this->fromAddress($draft, $account, $user);

        return DB::transaction(function () use ($draft, $user, $account, $from, $options) {
            $conversation = $draft->conversation ?? $this->createConversation($draft, $account, $from, $user);

            $parent = $draft->inReplyTo ?? ($draft->kind !== DraftKind::New
                ? $conversation->messages()->where('direction', MessageDirection::Inbound->value)->latest('sent_at')->first()
                : null);

            $references = $parent
                ? collect([...($parent->references ?? []), $parent->message_id_header])->filter()->unique()->values()->all()
                : [];

            $html = $this->sanitizer->sanitize($draft->html_body) ?? '';
            $text = $this->sanitizer->toText($html);
            $messageId = $this->fallback->generateMessageId($from->email);

            $message = $conversation->messages()->create([
                'mail_account_id' => $account?->id,
                'author_id' => $user->id,
                'direction' => MessageDirection::Outbound,
                'message_id_header' => $messageId,
                'in_reply_to' => $parent?->message_id_header,
                'references' => $references,
                'provider_thread_id' => $conversation->provider_thread_id,
                'from' => $from->toArray(),
                'to' => $this->addresses($draft->to),
                'cc' => $this->addresses($draft->cc),
                'bcc' => $this->addresses($draft->bcc),
                'reply_to' => $draft->identity?->reply_to ? [['name' => null, 'email' => $draft->identity->reply_to]] : [],
                'subject' => $this->subject($draft, $conversation),
                'snippet' => $this->sanitizer->snippet($text, $html),
                'text_body' => $text,
                'html_body' => $html,
                'mailbox_state' => ['inInbox' => false, 'isSent' => true, 'isRead' => true],
                'sent_at' => now(),
                'received_at' => now(),
                'is_read' => true,
                'has_attachments' => $draft->attachments->where('is_inline', false)->isNotEmpty(),
                'delivery_status' => DeliveryStatus::Queued,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            foreach ($draft->attachments as $attachment) {
                $newPath = sprintf('mail/%s/%s/%s', $conversation->id, $message->uuid, basename($attachment->path));
                Storage::disk($attachment->disk)->move($attachment->path, $newPath);

                $message->attachments()->create([
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'content_id' => $attachment->content_id,
                    'is_inline' => $attachment->is_inline,
                    'disk' => $attachment->disk,
                    'path' => $newPath,
                ]);
            }

            $draft->attachments()->each(fn ($attachment) => $attachment->forceDelete());
            $draft->forceDelete();

            if (! $conversation->subject || $conversation->subject === '(no subject)') {
                $conversation->subject = $message->subject;
                $conversation->normalized_subject = Conversation::normalizeSubject($message->subject);
            }

            $conversation->participants = collect($conversation->participants ?? [])
                ->merge([...$message->to ?? [], ...$message->cc ?? []])
                ->unique('email')
                ->values()
                ->all();

            if (($options['close'] ?? false) || ($options['status'] ?? null) instanceof ConversationStatus) {
                $target = ($options['status'] ?? null) ?: ConversationStatus::Closed;
                $previous = $conversation->status;

                if ($previous !== $target) {
                    $conversation->status = $target;
                    $conversation->closed_at = $target === ConversationStatus::Closed ? now() : null;
                    $conversation->closed_by_id = $target === ConversationStatus::Closed ? $user->id : null;
                    $conversation->events()->create([
                        'type' => ConversationEventType::StatusChanged,
                        'actor_id' => $user->id,
                        'payload' => ['from' => $previous->value, 'to' => $target->value],
                    ]);
                }
            }

            $conversation->save();
            $conversation->refreshCounters();

            DB::afterCommit(fn () => SendOutgoingMessage::dispatch($message));

            return $message;
        });
    }

    protected function createConversation(Draft $draft, ?MailAccount $account, Address $from, User $user): Conversation
    {
        $inbox = $draft->inbox;

        return Conversation::create([
            'organization_id' => $inbox->organization_id,
            'inbox_id' => $inbox->id,
            'mail_account_id' => $account?->id,
            'subject' => $draft->subject ?: '(no subject)',
            'status' => ConversationStatus::Open,
            'is_read' => true,
            'in_inbox' => false,
            'participants' => [],
            'first_message_at' => now(),
            'last_message_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    protected function defaultAccountFor(Inbox $inbox): ?MailAccount
    {
        return $inbox->mailAccounts()->orderBySendingPreference()->first();
    }

    protected function fromAddress(Draft $draft, ?MailAccount $account, User $user): Address
    {
        if ($draft->identity) {
            return new Address($draft->identity->email, $draft->identity->name);
        }

        if ($account) {
            $default = $account->identities->firstWhere('is_default', true);

            if ($default) {
                return new Address($default->email, $default->name);
            }

            if ($account->usesForwardingAddress() && $draft->inbox->email_address) {
                return new Address($draft->inbox->email_address, $draft->inbox->name);
            }

            return new Address($account->email_address, $account->display_name ?: $user->name);
        }

        if ($draft->inbox->email_address) {
            return new Address($draft->inbox->email_address, $draft->inbox->name);
        }

        return new Address((string) config('mail.from.address'), (string) config('mail.from.name'));
    }

    protected function subject(Draft $draft, Conversation $conversation): string
    {
        if ($draft->subject) {
            return $draft->subject;
        }

        $base = $conversation->subject ?? '';

        return match ($draft->kind) {
            DraftKind::Forward => preg_match('/^fwd?:/i', $base) ? $base : 'Fwd: '.$base,
            DraftKind::Reply, DraftKind::ReplyAll => preg_match('/^re:/i', $base) ? $base : 'Re: '.$base,
            default => $base,
        };
    }

    /**
     * @param  array<int, array{name?: string|null, email?: string|null}>|null  $items
     * @return array<int, array{name: string|null, email: string}>
     */
    protected function addresses(?array $items): array
    {
        return array_map(fn (Address $a) => $a->toArray(), Address::fromArrayList($items));
    }
}
