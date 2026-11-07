<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\UserSummaryResource;
use App\Models\Projects\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['lead', 'product', 'teams', 'members', 'modules', 'milestones', 'initiatives', 'links']);
        $user = $request->user();

        return [
            ...ProjectSummaryResource::make($this->resource)->resolve(),
            'description_html' => $this->description_html,
            'members' => $this->members->map(fn ($member) => [
                ...UserSummaryResource::make($member)->resolve(),
                'role' => $member->getRelation('membership')->role->value,
            ])->values()->all(),
            'modules' => ModuleResource::collection($this->modules)->resolve(),
            'milestones' => MilestoneResource::collection($this->milestones)->resolve(),
            'initiatives' => $this->initiatives->map(fn ($initiative) => ['id' => $initiative->id, 'uuid' => $initiative->uuid, 'slug' => $initiative->slug, 'name' => $initiative->name, 'color' => $initiative->color])->values()->all(),
            'links' => $this->links->map(fn ($link) => ['id' => $link->id, 'title' => $link->title, 'url' => $link->url])->values()->all(),
            'progress_detail' => $this->progress,
            'created_at' => $this->created_at?->toISOString(),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'manage_members' => $user?->can('manageMembers', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
