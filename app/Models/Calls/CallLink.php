<?php

namespace App\Models\Calls;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Ties a call to the issue or project it was about.
 *
 * @property int $id
 * @property int $call_id
 * @property string $linkable_type
 * @property int $linkable_id
 * @property int|null $created_by_id
 * @property-read Call $call
 * @property-read Model|null $linkable
 */
#[Fillable(['call_id', 'linkable_type', 'linkable_id', 'created_by_id'])]
class CallLink extends Model
{
    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

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
