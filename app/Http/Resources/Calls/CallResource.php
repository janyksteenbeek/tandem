<?php

namespace App\Http\Resources\Calls;

use App\Models\Calls\Call;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A call as its participants and its organization see it.
 *
 * @mixin Call
 */
class CallResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'title' => $this->title,
            'display_title' => $this->displayTitle(),
            'status' => $this->status->value,
            'settings' => $this->allSettings(),
            'has_password' => $this->hasPassword(),
            'join_url' => route('meet.show', ['call' => $this->code]),
            'host' => $this->host ? ['id' => $this->host->id, 'name' => $this->host->name, 'avatar' => $this->host->avatarUrl(), 'initials' => $this->host->initials()] : null,
            'organization' => ['name' => $this->organization->name, 'slug' => $this->organization->slug],
            'message_count' => $this->message_count,
            'file_count' => $this->file_count,
            'peak_participants' => $this->peak_participants,
            'duration_seconds' => $this->durationSeconds(),
            'started_at' => $this->started_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
