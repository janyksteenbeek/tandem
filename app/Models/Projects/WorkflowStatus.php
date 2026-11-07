<?php

namespace App\Models\Projects;

use App\Enums\Projects\StatusCategory;
use Carbon\CarbonInterface;
use Database\Factories\Projects\WorkflowStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workflow_id
 * @property string $name
 * @property string $color
 * @property string|null $icon
 * @property StatusCategory $category
 * @property int $position
 * @property bool $is_default
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Workflow $workflow
 */
#[Fillable(['workflow_id', 'name', 'color', 'icon', 'category', 'position', 'is_default'])]
class WorkflowStatus extends Model
{
    /** @use HasFactory<WorkflowStatusFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (WorkflowStatus $status) => $status->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    protected function casts(): array
    {
        return [
            'category' => StatusCategory::class,
            'is_default' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
