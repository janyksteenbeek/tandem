<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\UserSummaryResource;
use App\Models\Projects\Module;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Module
 */
class ModuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'product_id' => $this->product_id,
            'parent_id' => $this->parent_id,
            'position' => $this->position,
            'issues_count' => $this->issues_count,
            'open_issues_count' => $this->open_issues_count,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? UserSummaryResource::make($this->owner)->resolve() : null),
            'teams' => $this->whenLoaded('teams', fn () => TeamSummaryResource::collection($this->teams)->resolve()),
            'children' => $this->whenLoaded('children', fn () => ModuleResource::collection($this->children)->resolve()),
        ];
    }
}
