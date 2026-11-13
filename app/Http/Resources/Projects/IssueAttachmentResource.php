<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\UserSummaryResource;
use App\Models\Projects\IssueAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IssueAttachment
 */
class IssueAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'is_image' => $this->isImage(),
            'url' => route('projects.attachments.show', $this->uuid),
            'download_url' => route('projects.attachments.show', [$this->uuid, 'download' => 1]),
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy ? UserSummaryResource::make($this->uploadedBy)->resolve() : null, null),
            'source' => $this->source_conversation_id ? ['conversation_id' => $this->source_conversation_id] : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
