<?php

namespace App\Models\Projects;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workflow_id
 * @property int $from_status_id
 * @property int $to_status_id
 * @property-read Workflow $workflow
 */
#[Fillable(['workflow_id', 'from_status_id', 'to_status_id'])]
class WorkflowTransition extends Model
{
    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
