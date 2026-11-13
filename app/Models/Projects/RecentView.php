<?php

namespace App\Models\Projects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $organization_id
 * @property string $viewable_type
 * @property int $viewable_id
 * @property CarbonInterface $viewed_at
 */
#[Fillable(['user_id', 'organization_id', 'viewable_type', 'viewable_id', 'viewed_at'])]
class RecentView extends Model
{
    public $timestamps = false;

    /** @return MorphTo<Model, $this> */
    public function viewable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }
}
