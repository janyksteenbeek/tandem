<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $automation_rule_id
 * @property int|null $conversation_id
 * @property string $trigger
 * @property string $status
 * @property array<string, mixed>|null $details
 */
#[Fillable(['automation_rule_id', 'conversation_id', 'trigger', 'status', 'details', 'created_at'])]
class AutomationRun extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(fn (AutomationRun $run) => $run->created_at ??= now());
    }

    /** @return BelongsTo<AutomationRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }
}
