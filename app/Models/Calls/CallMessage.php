<?php

namespace App\Models\Calls;

use Carbon\CarbonInterface;
use Database\Factories\Calls\CallMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A chat message in a call. Plain text: links and line breaks are rendered by the client, so
 * nothing a guest types is ever interpreted as markup.
 *
 * @property int $id
 * @property string $uuid
 * @property int $call_id
 * @property int|null $participant_id
 * @property int|null $parent_id
 * @property string|null $client_id
 * @property string|null $body
 * @property string $type
 * @property CarbonInterface|null $edited_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Call $call
 * @property-read CallParticipant|null $participant
 * @property-read CallMessage|null $parent
 * @property-read Collection<int, CallMessageReaction> $reactions
 * @property-read Collection<int, CallFile> $files
 */
#[Fillable(['call_id', 'participant_id', 'parent_id', 'client_id', 'body', 'type', 'edited_at'])]
class CallMessage extends Model
{
    /** @use HasFactory<CallMessageFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (CallMessage $message) => $message->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /** @return BelongsTo<CallParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(CallParticipant::class, 'participant_id');
    }

    /** @return BelongsTo<CallMessage, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CallMessage::class, 'parent_id')->withTrashed();
    }

    /** @return HasMany<CallMessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(CallMessageReaction::class, 'message_id');
    }

    /** @return HasMany<CallFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(CallFile::class, 'message_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }
}
