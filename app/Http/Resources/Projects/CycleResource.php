<?php

namespace App\Http\Resources\Projects;

use App\Models\Projects\Cycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cycle
 */
class CycleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'team_id' => $this->team_id,
            'number' => $this->number,
            'name' => $this->displayName(),
            'goals' => $this->goals,
            'starts_at' => $this->starts_at->toDateString(),
            'ends_at' => $this->ends_at->toDateString(),
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'workstreams_count' => $this->workstreams_count,
            'completed_workstreams_count' => $this->completed_workstreams_count,
            'carried_over_count' => $this->carried_over_count,
            'progress' => $this->workstreams_count > 0 ? round($this->completed_workstreams_count / $this->workstreams_count, 3) : 0,
        ];
    }
}
