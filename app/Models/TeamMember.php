<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $team_id
 * @property int $user_id
 * @property TeamRole $role
 */
#[Fillable(['team_id', 'user_id', 'role'])]
class TeamMember extends Pivot
{
    protected $table = 'team_members';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['role' => TeamRole::class];
    }
}
