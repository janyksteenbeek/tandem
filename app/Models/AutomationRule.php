<?php

namespace App\Models;

use App\Enums\AutomationTrigger;
use Carbon\CarbonInterface;
use Database\Factories\AutomationRuleFactory;
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
 * @property int $organization_id
 * @property int|null $inbox_id
 * @property int|null $created_by_id
 * @property string $name
 * @property string|null $description
 * @property AutomationTrigger $trigger
 * @property string $match
 * @property array<int, array{field: string, operator: string, value: mixed}> $conditions
 * @property array<int, array{type: string, params?: array<string, mixed>}> $actions
 * @property bool $is_active
 * @property int $position
 * @property int|null $cooldown_minutes
 * @property int $run_count
 * @property CarbonInterface|null $last_run_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Organization $organization
 * @property-read Inbox|null $inbox
 * @property-read Collection<int, AutomationRun> $runs
 */
#[Fillable(['organization_id', 'inbox_id', 'created_by_id', 'name', 'description', 'trigger', 'match', 'conditions', 'actions', 'is_active', 'position', 'cooldown_minutes', 'run_count', 'last_run_at'])]
class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (AutomationRule $rule) => $rule->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Inbox, $this> */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    /** @return HasMany<AutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /** @return HasMany<AutoresponderSend, $this> */
    public function autoresponderSends(): HasMany
    {
        return $this->hasMany(AutoresponderSend::class);
    }

    protected function casts(): array
    {
        return [
            'trigger' => AutomationTrigger::class,
            'conditions' => 'array',
            'actions' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
