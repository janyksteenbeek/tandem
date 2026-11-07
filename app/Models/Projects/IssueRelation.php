<?php

namespace App\Models\Projects;

use App\Enums\Projects\IssueRelationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `blocks` is directional (issue blocks related issue); `related` and `duplicate` are stored once
 * with the lower id first.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $issue_id
 * @property int $related_issue_id
 * @property IssueRelationType $type
 * @property int|null $created_by_id
 * @property-read Issue $issue
 * @property-read Issue $relatedIssue
 * @property-read User|null $createdBy
 */
#[Fillable(['organization_id', 'issue_id', 'related_issue_id', 'type', 'created_by_id'])]
class IssueRelation extends Model
{
    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<Issue, $this> */
    public function relatedIssue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'related_issue_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    protected function casts(): array
    {
        return ['type' => IssueRelationType::class];
    }
}
