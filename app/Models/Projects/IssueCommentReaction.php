<?php

namespace App\Models\Projects;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $comment_id
 * @property int $user_id
 * @property string $emoji
 * @property CarbonInterface|null $created_at
 * @property-read IssueComment $comment
 * @property-read User|null $user
 */
#[Fillable(['comment_id', 'user_id', 'emoji', 'created_at'])]
class IssueCommentReaction extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(fn (IssueCommentReaction $reaction) => $reaction->created_at ??= now());
    }

    /** @return BelongsTo<IssueComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(IssueComment::class, 'comment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
