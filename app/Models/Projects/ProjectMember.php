<?php

namespace App\Models\Projects;

use App\Enums\Projects\ProjectMemberRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $project_id
 * @property int $user_id
 * @property ProjectMemberRole $role
 */
#[Fillable(['project_id', 'user_id', 'role'])]
class ProjectMember extends Pivot
{
    protected $table = 'project_members';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['role' => ProjectMemberRole::class];
    }
}
