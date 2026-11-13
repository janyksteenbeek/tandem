<?php

namespace App\Http\Resources\Calls;

use App\Models\Calls\CallFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CallFile
 */
class CallFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $url = route('meet.files.show', ['call' => $this->call->code, 'file' => $this->uuid]);

        return [
            'uuid' => $this->uuid,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'is_image' => $this->isImage(),
            'url' => $url,
            'download_url' => $url.'?download=1',
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
