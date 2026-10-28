<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
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
            'content_id' => $this->content_id,
            'is_inline' => $this->is_inline,
            'is_image' => $this->isImage(),
            'url' => route('attachments.show', $this->uuid),
            'download_url' => route('attachments.show', ['attachment' => $this->uuid, 'download' => 1]),
        ];
    }
}
