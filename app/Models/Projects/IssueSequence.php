<?php

namespace App\Models\Projects;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $scope_key
 * @property int|null $product_id
 * @property string $prefix
 * @property int $next_number
 * @property-read Organization $organization
 */
#[Fillable(['organization_id', 'scope_key', 'product_id', 'prefix', 'next_number'])]
class IssueSequence extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
