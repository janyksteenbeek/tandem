<?php

namespace App\Models;

use App\Enums\InboxRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $inbox_id
 * @property int $user_id
 * @property InboxRole $role
 */
#[Fillable(['inbox_id', 'user_id', 'role'])]
class InboxUser extends Pivot
{
    protected $table = 'inbox_users';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['role' => InboxRole::class];
    }
}
