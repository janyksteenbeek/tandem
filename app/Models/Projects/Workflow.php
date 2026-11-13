<?php

namespace App\Models\Projects;

use App\Enums\Projects\StatusCategory;
use App\Enums\Projects\WorkflowKind;
use App\Models\Organization;
use App\Models\Team;
use Carbon\CarbonInterface;
use Database\Factories\Projects\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A set of statuses. `issue` workflows describe the overall status of issues in an organization
 * or product; `team` workflows describe the status of one team's workstreams.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property WorkflowKind $kind
 * @property int|null $product_id
 * @property int|null $team_id
 * @property string $scope_key
 * @property string $name
 * @property bool $auto_start
 * @property bool $auto_complete
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Organization $organization
 * @property-read Team|null $team
 * @property-read Product|null $product
 * @property-read Collection<int, WorkflowStatus> $statuses
 * @property-read Collection<int, WorkflowTransition> $transitions
 */
#[Fillable(['organization_id', 'kind', 'product_id', 'team_id', 'scope_key', 'name', 'auto_start', 'auto_complete'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (Workflow $workflow) => $workflow->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<WorkflowStatus, $this> */
    public function statuses(): HasMany
    {
        return $this->hasMany(WorkflowStatus::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<WorkflowTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class);
    }

    public function defaultStatus(): WorkflowStatus
    {
        return $this->statuses->firstWhere('is_default', true) ?? $this->statuses->first() ?? throw new \RuntimeException('Workflow has no statuses.');
    }

    /**
     * The first status of a category, by position.
     */
    public function statusFor(StatusCategory $category): ?WorkflowStatus
    {
        return $this->statuses->first(fn (WorkflowStatus $status) => $status->category === $category);
    }

    protected function casts(): array
    {
        return [
            'kind' => WorkflowKind::class,
            'auto_start' => 'boolean',
            'auto_complete' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
