<?php

namespace App\Models\Projects;

use App\Models\Organization;
use Database\Factories\Projects\IssueLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Organization-wide or product-scoped label for issues.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $product_id
 * @property string $scope_key
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property string|null $description
 * @property-read Organization $organization
 * @property-read Product|null $product
 * @property-read Collection<int, Issue> $issues
 */
#[Fillable(['organization_id', 'product_id', 'scope_key', 'name', 'slug', 'color', 'description'])]
class IssueLabel extends Model
{
    /** @use HasFactory<IssueLabelFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (IssueLabel $label) {
            $label->slug = $label->slug ?: Str::slug($label->name);
            $label->scope_key = $label->product_id ? 'product:'.$label->product_id : 'org';
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsToMany<Issue, $this> */
    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'issue_label', 'label_id', 'issue_id')->withTimestamps();
    }
}
