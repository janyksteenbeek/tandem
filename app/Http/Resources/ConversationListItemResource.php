<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact representation for the middle pane and realtime updates.
 *
 * @mixin Conversation
 */
class ConversationListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['assignee', 'tags', 'inbox', 'shares']);

        $participants = collect($this->participants ?? []);
        $ownAddresses = $this->inbox->mailAccounts->pluck('email_address')->map(fn ($e) => strtolower($e));

        $others = $participants->reject(fn (array $p) => $ownAddresses->contains(strtolower($p['email'])))->values();
        $counterpart = $others->first() ?? $participants->first();

        return [
            'uuid' => $this->uuid,
            'inbox_id' => $this->inbox_id,
            'inbox_uuid' => $this->inbox->uuid,
            'inbox_name' => $this->inbox->name,
            'shared_inbox_ids' => $this->shares->pluck('inbox_id')->all(),
            'subject' => $this->subject,
            'snippet' => $this->snippet,
            'status' => $this->status->value,
            'priority' => $this->priority->key(),
            'is_read' => $this->is_read,
            'is_starred' => $this->is_starred,
            'in_inbox' => $this->in_inbox,
            'is_archived' => $this->is_archived,
            'is_trashed' => $this->is_trashed,
            'is_spam' => $this->is_spam,
            'unread_count' => $this->unread_count,
            'message_count' => $this->message_count,
            'comment_count' => $this->comment_count,
            'has_attachments' => $this->has_attachments,
            'has_draft' => $this->has_draft,
            'snoozed_until' => $this->snoozed_until?->toISOString(),
            'last_message_at' => $this->last_message_at?->toISOString(),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'last_direction' => $this->last_outbound_at && (! $this->last_inbound_at || $this->last_outbound_at->gt($this->last_inbound_at)) ? 'outbound' : 'inbound',
            'counterpart' => $counterpart,
            'participants' => $others->take(6)->values()->all(),
            'assignee' => $this->assignee ? UserSummaryResource::make($this->assignee)->resolve() : null,
            'tags' => TagResource::collection($this->tags)->resolve(),
        ];
    }
}
