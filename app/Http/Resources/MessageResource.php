<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['attachments', 'author']);

        $html = $this->html_body;

        foreach ($this->attachments as $attachment) {
            if ($attachment->content_id && $html) {
                $html = str_replace(
                    ['cid:'.$attachment->content_id, 'cid:&lt;'.$attachment->content_id.'&gt;'],
                    route('attachments.show', $attachment->uuid),
                    $html,
                );
            }
        }

        return [
            'uuid' => $this->uuid,
            'direction' => $this->direction->value,
            'from' => $this->from,
            'to' => $this->to ?? [],
            'cc' => $this->cc ?? [],
            'bcc' => $this->direction->value === 'outbound' ? ($this->bcc ?? []) : [],
            'reply_to' => $this->reply_to ?? [],
            'subject' => $this->subject,
            'snippet' => $this->snippet,
            'html_body' => $html,
            'text_body' => $this->text_body,
            'sent_at' => $this->sent_at?->toISOString(),
            'is_read' => $this->is_read,
            'is_draft' => $this->is_draft,
            'is_starred' => $this->is_starred,
            'has_attachments' => $this->has_attachments,
            'delivery_status' => $this->delivery_status->value,
            'delivery_error' => $this->delivery_error,
            'author' => $this->author ? UserSummaryResource::make($this->author) : null,
            'attachments' => AttachmentResource::collection($this->attachments->filter(fn (Attachment $a) => ! $a->is_inline || ! $a->content_id)->values()),
            'message_id' => $this->message_id_header,
        ];
    }
}
