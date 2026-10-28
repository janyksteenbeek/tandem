<?php

namespace App\Models;

use App\Enums\DraftKind;
use Database\Factories\DraftFactory;
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
 * @property int|null $conversation_id
 * @property int $inbox_id
 * @property int|null $mail_account_id
 * @property int|null $sender_identity_id
 * @property int $user_id
 * @property int|null $in_reply_to_message_id
 * @property DraftKind $kind
 * @property array<int, array{name: string|null, email: string}>|null $to
 * @property array<int, array{name: string|null, email: string}>|null $cc
 * @property array<int, array{name: string|null, email: string}>|null $bcc
 * @property string|null $subject
 * @property string|null $html_body
 * @property bool $is_shared
 * @property int|null $locked_by_id
 * @property Carbon|null $locked_at
 * @property string|null $provider_draft_id
 * @property Carbon|null $last_saved_at
 * @property-read Conversation|null $conversation
 * @property-read Inbox $inbox
 * @property-read MailAccount|null $mailAccount
 * @property-read SenderIdentity|null $identity
 * @property-read User $user
 * @property-read User|null $lockedBy
 * @property-read Message|null $inReplyTo
 * @property-read Collection<int, DraftAttachment> $attachments
 */
#[Fillable([
    'conversation_id', 'inbox_id', 'mail_account_id', 'sender_identity_id', 'user_id', 'in_reply_to_message_id', 'kind',
    'to', 'cc', 'bcc', 'subject', 'html_body', 'is_shared', 'locked_by_id', 'locked_at', 'provider_draft_id', 'last_saved_at',
])]
class Draft extends Model
{
    /** @use HasFactory<DraftFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (Draft $draft) => $draft->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Inbox, $this> */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /** @return BelongsTo<SenderIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(SenderIdentity::class, 'sender_identity_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'in_reply_to_message_id');
    }

    /** @return HasMany<DraftAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(DraftAttachment::class);
    }

    public function isLockedByOther(User $user): bool
    {
        return $this->locked_by_id !== null
            && $this->locked_by_id !== $user->id
            && $this->locked_at?->gt(now()->subMinutes(2)) === true;
    }

    protected function casts(): array
    {
        return [
            'kind' => DraftKind::class,
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'is_shared' => 'boolean',
            'locked_at' => 'datetime',
            'last_saved_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
