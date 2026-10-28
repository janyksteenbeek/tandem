<?php

namespace App\Http\Resources;

use App\Models\Draft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Draft
 */
class DraftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['attachments', 'user', 'identity', 'lockedBy']);

        return [
            'uuid' => $this->uuid,
            'conversation_uuid' => $this->conversation?->uuid,
            'inbox_uuid' => $this->inbox->uuid,
            'kind' => $this->kind->value,
            'mail_account_uuid' => $this->mailAccount?->uuid,
            'sender_identity_id' => $this->sender_identity_id,
            'in_reply_to_uuid' => $this->inReplyTo?->uuid,
            'to' => $this->to ?? [],
            'cc' => $this->cc ?? [],
            'bcc' => $this->bcc ?? [],
            'subject' => $this->subject,
            'html_body' => $this->html_body,
            'is_shared' => $this->is_shared,
            'user' => UserSummaryResource::make($this->user)->resolve(),
            'locked_by' => $this->lockedBy && $this->isLockedByOther($request->user()) ? UserSummaryResource::make($this->lockedBy)->resolve() : null,
            'attachments' => $this->attachments->map(fn ($a) => [
                'uuid' => $a->uuid,
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
                'is_inline' => $a->is_inline,
            ])->values(),
            'last_saved_at' => $this->last_saved_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
