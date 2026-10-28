<?php

namespace App\Models;

use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use Carbon\CarbonInterface;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $organization_id
 * @property int $inbox_id
 * @property int|null $origin_inbox_id
 * @property int|null $mail_account_id
 * @property string|null $subject
 * @property string|null $normalized_subject
 * @property string|null $snippet
 * @property ConversationStatus $status
 * @property int|null $assignee_id
 * @property int|null $assigned_by_id
 * @property CarbonInterface|null $assigned_at
 * @property ConversationPriority $priority
 * @property bool $is_starred
 * @property bool $is_read
 * @property int $unread_count
 * @property int $message_count
 * @property int $comment_count
 * @property bool $has_attachments
 * @property bool $has_draft
 * @property bool $in_inbox
 * @property bool $is_archived
 * @property bool $is_trashed
 * @property bool $is_spam
 * @property CarbonInterface|null $snoozed_until
 * @property int|null $snoozed_by_id
 * @property CarbonInterface|null $closed_at
 * @property int|null $closed_by_id
 * @property CarbonInterface|null $first_message_at
 * @property CarbonInterface|null $last_message_at
 * @property CarbonInterface|null $last_inbound_at
 * @property CarbonInterface|null $last_outbound_at
 * @property CarbonInterface|null $last_activity_at
 * @property array<int, array{name: string|null, email: string}>|null $participants
 * @property string|null $provider_thread_id
 * @property-read Inbox $inbox
 * @property-read Inbox|null $originInbox
 * @property-read MailAccount|null $mailAccount
 * @property-read Organization|null $organization
 * @property-read User|null $assignee
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, Comment> $comments
 * @property-read Collection<int, ConversationEvent> $events
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, User> $followers
 * @property-read Collection<int, ConversationShare> $shares
 * @property-read Collection<int, Draft> $drafts
 */
#[Fillable([
    'organization_id', 'inbox_id', 'origin_inbox_id', 'mail_account_id', 'subject', 'normalized_subject', 'snippet',
    'status', 'assignee_id', 'assigned_by_id', 'assigned_at', 'priority', 'is_starred', 'is_read', 'unread_count',
    'message_count', 'comment_count', 'has_attachments', 'has_draft', 'in_inbox', 'is_archived', 'is_trashed',
    'is_spam', 'snoozed_until', 'snoozed_by_id', 'closed_at', 'closed_by_id', 'first_message_at', 'last_message_at',
    'last_inbound_at', 'last_outbound_at', 'last_activity_at', 'participants', 'provider_thread_id',
])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation) {
            $conversation->uuid ??= (string) Str::uuid();
            $conversation->normalized_subject ??= static::normalizeSubject($conversation->subject);
        });
    }

    /**
     * Strip reply/forward prefixes so loosely threaded mail can be grouped.
     */
    public static function normalizeSubject(?string $subject): string
    {
        $subject = trim((string) $subject);
        $previous = null;

        while ($previous !== $subject) {
            $previous = $subject;
            $subject = trim((string) preg_replace('/^((re|fw|fwd|aw|wg|tr|sv|vs|antw)\s*(\[\d+\])?\s*:\s*)+/i', '', $subject));
        }

        return Str::limit(Str::lower($subject), 240, '');
    }

    /** @return BelongsTo<Inbox, $this> */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    /** @return BelongsTo<Inbox, $this> */
    public function originInbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class, 'origin_inbox_id');
    }

    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('sent_at')->orderBy('id');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('created_at');
    }

    /** @return HasMany<ConversationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ConversationEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<Draft, $this> */
    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_followers')->withTimestamps();
    }

    /** @return HasMany<ConversationShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(ConversationShare::class);
    }

    /**
     * Conversations visible inside the given inbox: owned by it or explicitly shared into it.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeVisibleInInbox(Builder $query, Inbox $inbox): Builder
    {
        return $query->where(function (Builder $q) use ($inbox) {
            $q->where('inbox_id', $inbox->id)
                ->orWhereHas('shares', fn (Builder $shares) => $shares->where('inbox_id', $inbox->id));
        });
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $boolean = collect(preg_split('/\s+/', $term) ?: [])
            ->filter()
            ->map(fn (string $word) => '+'.preg_replace('/[+\-><()~*"@]+/', '', $word).'*')
            ->implode(' ');

        return $query->where(function (Builder $q) use ($boolean, $term) {
            $q->whereRaw('MATCH(conversations.subject, conversations.snippet) AGAINST (? IN BOOLEAN MODE)', [$boolean])
                ->orWhereHas('messages', fn (Builder $m) => $m->whereRaw('MATCH(messages.subject, messages.snippet, messages.text_body) AGAINST (? IN BOOLEAN MODE)', [$boolean]))
                ->orWhere('subject', 'like', '%'.$term.'%')
                ->orWhereRaw('JSON_SEARCH(LOWER(participants), "one", ?) IS NOT NULL', ['%'.Str::lower($term).'%']);
        });
    }

    public function isOpen(): bool
    {
        return $this->status === ConversationStatus::Open;
    }

    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    public function isSharedInto(Inbox $inbox): bool
    {
        return $this->inbox_id !== $inbox->id && $this->shares()->where('inbox_id', $inbox->id)->exists();
    }

    /**
     * Recompute denormalised counters from messages. Cheap enough to run after each ingest.
     */
    public function refreshCounters(): void
    {
        $messages = $this->messages()->where('is_draft', false);

        $first = (clone $messages)->min('sent_at');
        $last = (clone $messages)->max('sent_at');
        $lastInbound = (clone $messages)->where('direction', 'inbound')->max('sent_at');
        $lastOutbound = (clone $messages)->where('direction', 'outbound')->max('sent_at');
        $unread = (clone $messages)->where('is_read', false)->count();

        $this->forceFill([
            'message_count' => (clone $messages)->count(),
            'unread_count' => $unread,
            'is_read' => $unread === 0,
            'has_attachments' => (clone $messages)->where('has_attachments', true)->exists(),
            'has_draft' => $this->drafts()->exists(),
            'first_message_at' => $first,
            'last_message_at' => $last,
            'last_inbound_at' => $lastInbound,
            'last_outbound_at' => $lastOutbound,
            'last_activity_at' => $last ?? $this->last_activity_at ?? now(),
            'comment_count' => $this->comments()->count(),
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'priority' => ConversationPriority::class,
            'is_starred' => 'boolean',
            'is_read' => 'boolean',
            'has_attachments' => 'boolean',
            'has_draft' => 'boolean',
            'in_inbox' => 'boolean',
            'is_archived' => 'boolean',
            'is_trashed' => 'boolean',
            'is_spam' => 'boolean',
            'assigned_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'closed_at' => 'datetime',
            'first_message_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'participants' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
