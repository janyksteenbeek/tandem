<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\UserSummaryResource;
use App\Models\Projects\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $progress = $this->progress ?? [];

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'color' => $this->color,
            'icon' => $this->icon,
            'status' => $this->status->value,
            'health' => $this->health->value,
            'priority' => $this->priority,
            'visibility' => $this->visibility->value,
            'start_date' => $this->start_date?->toDateString(),
            'target_date' => $this->target_date?->toDateString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? UserSummaryResource::make($this->lead)->resolve() : null, null),
            'product' => $this->whenLoaded('product', fn () => $this->product ? ProductSummaryResource::make($this->product)->resolve() : null, null),
            'teams' => $this->whenLoaded('teams', fn () => TeamSummaryResource::collection($this->teams)->resolve(), []),
            'progress' => (float) ($progress['percent'] ?? 0) / 100,
            'counts' => [
                'issues' => $this->issues_count,
                'completed' => $this->completed_issues_count,
                'started' => $this->started_issues_count,
                'canceled' => $this->canceled_issues_count,
            ],
        ];
    }
}
