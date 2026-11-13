<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\UserSummaryResource;
use App\Models\Projects\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['owner', 'teams', 'members', 'modules.owner', 'modules.teams', 'links', 'labels']);
        $user = $request->user();

        return [
            ...ProductSummaryResource::make($this->resource)->resolve(),
            'description' => $this->description,
            'owner' => $this->owner ? UserSummaryResource::make($this->owner)->resolve() : null,
            'teams' => TeamSummaryResource::collection($this->teams)->resolve(),
            'members' => $this->members->map(fn ($member) => [
                ...UserSummaryResource::make($member)->resolve(),
                'role' => $member->getRelation('membership')->role->value,
            ])->values()->all(),
            'modules' => ModuleResource::collection($this->modules)->resolve(),
            'labels' => IssueLabelResource::collection($this->labels)->resolve(),
            'links' => $this->links->map(fn ($link) => ['id' => $link->id, 'title' => $link->title, 'url' => $link->url])->values()->all(),
            'issue_workflow_id' => $this->issue_workflow_id,
            'created_at' => $this->created_at?->toISOString(),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'manage_members' => $user?->can('manageMembers', $this->resource) ?? false,
                'manage_workflow' => $user?->can('manageWorkflow', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
