<?php

namespace App\Http\Resources\Calls;

use App\Models\Calls\CallRecording;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The state of a recording as everyone in the call may see it: no paths, no share token.
 *
 * @mixin CallRecording
 */
class CallRecordingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'mode' => $this->mode->value,
            'mode_label' => $this->mode->label(),
            'status' => $this->status->value,
            'is_active' => $this->status->isActive(),
            'is_playable' => $this->status->isPlayable(),
            'started_at' => $this->started_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'duration_ms' => $this->duration_ms,
            'size_bytes' => $this->size_bytes,
            'track_count' => count($this->tracks()),
            'has_screen_share' => $this->hasScreenShare(),
            'started_by' => $this->startedBy?->name,
            'failure_reason' => $this->failure_reason,
            'warning' => $this->last_warning,
            'expires_at' => $this->expires_at?->toISOString(),
        ];
    }
}
