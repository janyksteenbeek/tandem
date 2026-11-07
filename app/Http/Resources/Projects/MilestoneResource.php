<?php

namespace App\Http\Resources\Projects;

use App\Models\Projects\Milestone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Milestone
 */
class MilestoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'target_date' => $this->target_date?->toDateString(),
            'status' => $this->status->value,
            'position' => $this->position,
            'project_id' => $this->project_id,
            'initiative_id' => $this->initiative_id,
            'issues_count' => $this->issues_count,
            'completed_issues_count' => $this->completed_issues_count,
            'progress' => $this->issues_count > 0 ? round($this->completed_issues_count / $this->issues_count, 3) : 0,
        ];
    }
}
