<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\UserSummaryResource;
use App\Models\Projects\IssueWorkstream;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IssueWorkstream
 */
class WorkstreamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['team', 'status', 'assignees', 'cycle']);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'issue_id' => $this->issue_id,
            'team' => TeamSummaryResource::make($this->team)->resolve(),
            'responsibility' => $this->responsibility,
            'status' => WorkflowStatusResource::make($this->status)->resolve(),
            'assignees' => UserSummaryResource::collection($this->assignees)->resolve(),
            'estimate' => $this->estimate !== null ? (float) $this->estimate : null,
            'due_date' => $this->due_date?->toDateString(),
            'is_required' => $this->is_required,
            'cycle' => $this->cycle ? CycleResource::make($this->cycle)->resolve() : null,
            'blocked_by_count' => $this->blocked_by_count,
            'position' => $this->position,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
