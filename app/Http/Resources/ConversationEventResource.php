<?php

namespace App\Http\Resources;

use App\Models\ConversationEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConversationEvent
 */
class ConversationEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'payload' => $this->payload ?? [],
            'actor' => $this->actor ? UserSummaryResource::make($this->actor) : null,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
