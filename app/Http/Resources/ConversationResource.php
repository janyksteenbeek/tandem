<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full representation for the right pane.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['messages.attachments', 'messages.author', 'comments.user', 'events.actor', 'tags', 'assignee', 'followers', 'inbox', 'originInbox', 'shares.inbox', 'shares.sharedBy', 'mailAccount']);

        $user = $request->user();

        $drafts = $this->drafts()
            ->with(['attachments', 'user', 'identity'])
            ->where(fn ($q) => $q->where('user_id', $user?->id)->orWhere('is_shared', true))
            ->get();

        return [
            ...ConversationListItemResource::make($this->resource)->resolve(),
            'organization_id' => $this->organization_id,
            'mail_account' => $this->mailAccount ? [
                'uuid' => $this->mailAccount->uuid,
                'email' => $this->mailAccount->email_address,
                'provider' => $this->mailAccount->provider->value,
            ] : null,
            'origin_inbox' => $this->originInbox ? ['uuid' => $this->originInbox->uuid, 'name' => $this->originInbox->name] : null,
            'shares' => $this->shares->map(fn ($share) => [
                'inbox_uuid' => $share->inbox->uuid,
                'inbox_name' => $share->inbox->name,
                'shared_by' => $share->sharedBy ? UserSummaryResource::make($share->sharedBy)->resolve() : null,
                'note' => $share->note,
                'created_at' => $share->created_at?->toISOString(),
            ])->values(),
            'followers' => UserSummaryResource::collection($this->followers)->resolve(),
            'is_following' => $user ? $this->followers->contains('id', $user->id) : false,
            'messages' => MessageResource::collection($this->messages)->resolve(),
            'comments' => CommentResource::collection($this->comments)->resolve(),
            'events' => ConversationEventResource::collection($this->events)->resolve(),
            'drafts' => DraftResource::collection($drafts)->resolve(),
            'closed_at' => $this->closed_at?->toISOString(),
            'first_message_at' => $this->first_message_at?->toISOString(),
            'can' => [
                'act' => $user?->can('act', $this->resource) ?? false,
                'share' => $user?->can('share', $this->resource) ?? false,
                'move' => $user?->can('move', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
