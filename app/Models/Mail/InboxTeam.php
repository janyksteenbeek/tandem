<?php

namespace App\Models\Mail;

use App\Enums\Mail\InboxRole;
use App\Models\Team;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $inbox_id
 * @property int $team_id
 * @property InboxRole $role
 * @property-read Team $team
 */
#[Fillable(['inbox_id', 'team_id', 'role'])]
class InboxTeam extends Pivot
{
    protected $table = 'inbox_teams';

    public $incrementing = true;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return ['role' => InboxRole::class];
    }
}
