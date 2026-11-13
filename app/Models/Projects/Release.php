<?php

namespace App\Models\Projects;

use App\Enums\Projects\ReleaseStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Projects\ReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A product delivery milestone or version (Frontier 2.0).
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property string $name
 * @property string|null $version
 * @property ReleaseStatus $status
 * @property CarbonInterface|null $planned_at
 * @property CarbonInterface|null $released_at
 * @property string|null $notes_html
 * @property int|null $created_by_id
 * @property int $issues_count
 * @property int $completed_issues_count
 * @property-read Product $product
 * @property-read User|null $createdBy
 * @property-read Collection<int, Issue> $issues
 */
#[Fillable(['product_id', 'name', 'version', 'status', 'planned_at', 'released_at', 'notes_html', 'created_by_id'])]
class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (Release $release) => $release->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsToMany<Issue, $this> */
    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'issue_release')->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'status' => ReleaseStatus::class,
            'planned_at' => 'date',
            'released_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
