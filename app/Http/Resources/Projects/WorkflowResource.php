<?php

namespace App\Http\Resources\Projects;

use App\Models\Projects\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workflow
 */
class WorkflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['statuses', 'transitions']);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'team_id' => $this->team_id,
            'product_id' => $this->product_id,
            'auto_start' => $this->auto_start,
            'auto_complete' => $this->auto_complete,
            'statuses' => WorkflowStatusResource::collection($this->statuses)->resolve(),
            'transitions' => $this->transitions->map(fn ($t) => ['from' => $t->from_status_id, 'to' => $t->to_status_id])->values()->all(),
        ];
    }
}
