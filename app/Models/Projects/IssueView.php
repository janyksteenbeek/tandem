<?php

namespace App\Models\Projects;

use App\Enums\Projects\IssueViewScope;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Database\Factories\Projects\IssueViewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A saved issue view: filters plus display options, personal, team-shared or organization-wide.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property IssueViewScope $scope
 * @property int|null $user_id
 * @property int|null $team_id
 * @property int|null $project_id
 * @property int|null $product_id
 * @property string $name
 * @property string|null $icon
 * @property string|null $color
 * @property array<string, mixed>|null $filters
 * @property array<string, mixed>|null $display
 * @property int $position
 * @property int|null $created_by_id
 * @property-read Organization $organization
 * @property-read User|null $user
 * @property-read Team|null $team
 * @property-read Project|null $project
 * @property-read Product|null $product
 */
#[Fillable(['organization_id', 'scope', 'user_id', 'team_id', 'project_id', 'product_id', 'name', 'icon', 'color', 'filters', 'display', 'position', 'created_by_id'])]
class IssueView extends Model
{
    /** @use HasFactory<IssueViewFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (IssueView $view) => $view->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'scope' => IssueViewScope::class,
            'filters' => 'array',
            'display' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
