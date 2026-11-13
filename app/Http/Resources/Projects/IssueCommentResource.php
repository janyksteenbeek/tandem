<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\UserSummaryResource;
use App\Models\Projects\IssueComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IssueComment
 */
class IssueCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['user', 'reactions.user', 'attachments']);
        $user = $request->user();

        $reactions = $this->reactions->groupBy('emoji')->map(fn ($group, $emoji) => [
            'emoji' => $emoji,
            'count' => $group->count(),
            'users' => $group->map(fn ($r) => ['id' => $r->user?->id, 'name' => $r->user?->name])->values()->all(),
            'mine' => $user ? $group->contains('user_id', $user->id) : false,
        ])->values()->all();

        return [
            'uuid' => $this->uuid,
            'parent_uuid' => $this->parent?->uuid,
            'body' => $this->body_html,
            'mentions' => $this->mentions ?? [],
            'user' => $this->user ? UserSummaryResource::make($this->user)->resolve() : null,
            'reactions' => $reactions,
            'attachments' => IssueAttachmentResource::collection($this->attachments)->resolve(),
            'created_at' => $this->created_at?->toISOString(),
            'edited_at' => $this->edited_at?->toISOString(),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
