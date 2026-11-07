<?php

namespace App\Models\Projects;

use App\Enums\Projects\IssueActivityType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of an issue's timeline. Mirrors ConversationEvent for mail.
 *
 * @property int $id
 * @property int $issue_id
 * @property int|null $workstream_id
 * @property int|null $actor_id
 * @property IssueActivityType $type
 * @property array<string, mixed>|null $payload
 * @property CarbonInterface $created_at
 * @property-read Issue $issue
 * @property-read IssueWorkstream|null $workstream
 * @property-read User|null $actor
 */
#[Fillable(['issue_id', 'workstream_id', 'actor_id', 'type', 'payload', 'created_at'])]
class IssueActivity extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(fn (IssueActivity $activity) => $activity->created_at ??= now());
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<IssueWorkstream, $this> */
    public function workstream(): BelongsTo
    {
        return $this->belongsTo(IssueWorkstream::class, 'workstream_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'type' => IssueActivityType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
