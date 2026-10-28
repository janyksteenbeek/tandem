<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property int|null $user_id
 * @property int|null $inbox_id
 * @property string $name
 * @property string|null $icon
 * @property array<string, mixed> $filters
 * @property bool $is_shared
 * @property int $position
 */
#[Fillable(['organization_id', 'user_id', 'inbox_id', 'name', 'icon', 'filters', 'is_shared', 'position'])]
class SavedView extends Model
{
    /** @return BelongsTo<Inbox, $this> */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_shared' => 'boolean',
        ];
    }
}
