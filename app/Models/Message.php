<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $conversation_id
 * @property int|null $mail_account_id
 * @property int|null $author_id
 * @property int|null $folder_id
 * @property MessageDirection $direction
 * @property string|null $message_id_header
 * @property string|null $message_id_hash
 * @property string|null $in_reply_to
 * @property array<int, string>|null $references
 * @property string|null $provider_message_id
 * @property string|null $provider_thread_id
 * @property int|null $imap_uid
 * @property array{name: string|null, email: string}|null $from
 * @property array<int, array{name: string|null, email: string}>|null $to
 * @property array<int, array{name: string|null, email: string}>|null $cc
 * @property array<int, array{name: string|null, email: string}>|null $bcc
 * @property array<int, array{name: string|null, email: string}>|null $reply_to
 * @property string|null $subject
 * @property string|null $snippet
 * @property string|null $text_body
 * @property string|null $html_body
 * @property array<string, mixed>|null $headers
 * @property array<int, string>|null $provider_labels
 * @property Carbon|null $sent_at
 * @property Carbon|null $received_at
 * @property bool $is_read
 * @property bool $is_draft
 * @property bool $is_starred
 * @property bool $has_attachments
 * @property int|null $size_bytes
 * @property DeliveryStatus $delivery_status
 * @property string|null $delivery_error
 * @property int $send_attempts
 * @property string|null $idempotency_key
 * @property string|null $raw_path
 * @property-read Conversation $conversation
 * @property-read MailAccount|null $mailAccount
 * @property-read User|null $author
 * @property-read Collection<int, Attachment> $attachments
 */
#[Fillable([
    'conversation_id', 'mail_account_id', 'author_id', 'folder_id', 'direction', 'message_id_header', 'message_id_hash',
    'in_reply_to', 'references', 'provider_message_id', 'provider_thread_id', 'imap_uid', 'from', 'to', 'cc', 'bcc',
    'reply_to', 'subject', 'snippet', 'text_body', 'html_body', 'headers', 'provider_labels', 'sent_at', 'received_at',
    'is_read', 'is_draft', 'is_starred', 'has_attachments', 'size_bytes', 'delivery_status', 'delivery_error',
    'send_attempts', 'idempotency_key', 'raw_path',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Message $message) {
            $message->uuid ??= (string) Str::uuid();

            if ($message->message_id_header && ! $message->message_id_hash) {
                $message->message_id_hash = static::hashMessageId($message->message_id_header);
            }
        });
    }

    public static function hashMessageId(string $messageId): string
    {
        return sha1(Str::lower(trim($messageId, " \t\n\r<>")));
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<MailFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MailFolder::class, 'folder_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === MessageDirection::Inbound;
    }

    public function isOutbound(): bool
    {
        return $this->direction === MessageDirection::Outbound;
    }

    /**
     * All recipient addresses (to + cc + bcc), lowercased and unique.
     *
     * @return array<int, string>
     */
    public function recipientAddresses(): array
    {
        return collect([...($this->to ?? []), ...($this->cc ?? []), ...($this->bcc ?? [])])
            ->pluck('email')
            ->filter()
            ->map(fn (string $email) => Str::lower($email))
            ->unique()
            ->values()
            ->all();
    }

    public function fromAddress(): ?string
    {
        return $this->from['email'] ?? null;
    }

    public function fromName(): ?string
    {
        return $this->from['name'] ?? null;
    }

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'delivery_status' => DeliveryStatus::class,
            'references' => 'array',
            'from' => 'array',
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'reply_to' => 'array',
            'headers' => 'array',
            'provider_labels' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'is_read' => 'boolean',
            'is_draft' => 'boolean',
            'is_starred' => 'boolean',
            'has_attachments' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
