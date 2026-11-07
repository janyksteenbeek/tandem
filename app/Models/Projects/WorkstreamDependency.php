<?php

namespace App\Models\Projects;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workstream waits for another workstream or a whole issue. Exactly one target is set.
 *
 * @property int $id
 * @property int $workstream_id
 * @property int|null $depends_on_workstream_id
 * @property int|null $depends_on_issue_id
 * @property int|null $created_by_id
 * @property CarbonInterface|null $resolved_at
 * @property-read IssueWorkstream $workstream
 * @property-read IssueWorkstream|null $dependsOnWorkstream
 * @property-read Issue|null $dependsOnIssue
 * @property-read User|null $createdBy
 */
#[Fillable(['workstream_id', 'depends_on_workstream_id', 'depends_on_issue_id', 'created_by_id', 'resolved_at'])]
class WorkstreamDependency extends Model
{
    /** @return BelongsTo<IssueWorkstream, $this> */
    public function workstream(): BelongsTo
    {
        return $this->belongsTo(IssueWorkstream::class, 'workstream_id');
    }

    /** @return BelongsTo<IssueWorkstream, $this> */
    public function dependsOnWorkstream(): BelongsTo
    {
        return $this->belongsTo(IssueWorkstream::class, 'depends_on_workstream_id');
    }

    /** @return BelongsTo<Issue, $this> */
    public function dependsOnIssue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'depends_on_issue_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
