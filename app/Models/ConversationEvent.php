<?php

namespace App\Models;

use App\Enums\ConversationEventType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int|null $actor_id
 * @property ConversationEventType $type
 * @property array<string, mixed>|null $payload
 * @property CarbonInterface $created_at
 * @property-read Conversation $conversation
 * @property-read User|null $actor
 */
#[Fillable(['conversation_id', 'actor_id', 'type', 'payload', 'created_at'])]
class ConversationEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(fn (ConversationEvent $event) => $event->created_at ??= now());
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'type' => ConversationEventType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
