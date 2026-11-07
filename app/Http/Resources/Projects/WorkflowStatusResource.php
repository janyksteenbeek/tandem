<?php

namespace App\Http\Resources\Projects;

use App\Models\Projects\WorkflowStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowStatus
 */
class WorkflowStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'workflow_id' => $this->workflow_id,
            'name' => $this->name,
            'color' => $this->color,
            'icon' => $this->icon,
            'category' => $this->category->value,
            'position' => $this->position,
            'is_default' => $this->is_default,
        ];
    }
}
