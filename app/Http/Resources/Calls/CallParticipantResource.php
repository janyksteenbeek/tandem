<?php

namespace App\Http\Resources\Calls;

use App\Models\Calls\CallParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately viewer-independent and free of contact details: the same payload is broadcast
 * to everyone in the call, guests included.
 *
 * @mixin CallParticipant
 */
class CallParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'identity' => $this->identity(),
            'name' => $this->name,
            'initials' => $this->initials(),
            'avatar' => $this->avatarUrl(),
            'role' => $this->role->value,
            'status' => $this->status->value,
            'is_guest' => $this->isGuest(),
            'joined_at' => $this->joined_at?->toISOString(),
            'left_at' => $this->left_at?->toISOString(),
        ];
    }
}
