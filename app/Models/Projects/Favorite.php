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
 * @property string $favoritable_type
 * @property int $favoritable_id
 * @property int $position
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['user_id', 'organization_id', 'favoritable_type', 'favoritable_id', 'position', 'created_at'])]
class Favorite extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(fn (Favorite $favorite) => $favorite->created_at ??= now());
    }

    /** @return MorphTo<Model, $this> */
    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
