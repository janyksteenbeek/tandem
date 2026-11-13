<?php

namespace App\Models\Projects;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An external link (documentation, design, ticket) attached to a product, project or issue.
 *
 * @property int $id
 * @property string $linkable_type
 * @property int $linkable_id
 * @property string $title
 * @property string $url
 * @property int $position
 * @property int|null $created_by_id
 * @property-read User|null $createdBy
 */
#[Fillable(['linkable_type', 'linkable_id', 'title', 'url', 'position', 'created_by_id'])]
class EntityLink extends Model
{
    /** @return MorphTo<Model, $this> */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
