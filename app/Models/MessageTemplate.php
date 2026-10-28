<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $inbox_id
 * @property int|null $created_by_id
 * @property string $name
 * @property string|null $category
 * @property string|null $subject
 * @property string $body_html
 */
#[Fillable(['organization_id', 'inbox_id', 'created_by_id', 'name', 'category', 'subject', 'body_html'])]
class MessageTemplate extends Model
{
    use SoftDeletes;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Inbox, $this> */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }
}
