<?php

namespace App\Models\Projects;

use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Projects\IssueCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $issue_id
 * @property int|null $user_id
 * @property int|null $parent_id
 * @property string $body_html
 * @property string|null $body_text
 * @property array<int, int>|null $mentions
 * @property CarbonInterface|null $edited_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Issue $issue
 * @property-read User|null $user
 * @property-read IssueComment|null $parent
 * @property-read Collection<int, IssueCommentReaction> $reactions
 * @property-read Collection<int, IssueAttachment> $attachments
 */
#[Fillable(['issue_id', 'user_id', 'parent_id', 'body_html', 'body_text', 'mentions', 'edited_at'])]
class IssueComment extends Model
{
    /** @use HasFactory<IssueCommentFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (IssueComment $comment) => $comment->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<IssueComment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(IssueComment::class, 'parent_id');
    }

    /** @return HasMany<IssueCommentReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(IssueCommentReaction::class, 'comment_id');
    }

    /** @return HasMany<IssueAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(IssueAttachment::class, 'comment_id');
    }

    protected function casts(): array
    {
        return [
            'mentions' => 'array',
            'edited_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
