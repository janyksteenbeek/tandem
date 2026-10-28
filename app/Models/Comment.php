<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $conversation_id
 * @property int $user_id
 * @property string $body
 * @property array<int, int>|null $mentions
 * @property CarbonInterface|null $edited_at
 * @property-read Conversation $conversation
 * @property-read User $user
 */
#[Fillable(['conversation_id', 'user_id', 'body', 'mentions', 'edited_at'])]
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (Comment $comment) => $comment->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
