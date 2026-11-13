<?php

namespace App\Http\Resources\Calls;

use App\Models\Calls\CallMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Viewer-independent, so one payload serves the HTTP response and the broadcast. The browser
 * works out "mine" from the participant uuids.
 *
 * @mixin CallMessage
 */
class CallMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['participant.user', 'reactions.participant', 'files.call', 'parent.participant']);
        $deleted = $this->trashed();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'client_id' => $this->client_id,
            'body' => $deleted ? null : $this->body,
            'deleted' => $deleted,
            'author' => $this->participant ? [
                'uuid' => $this->participant->uuid,
                'name' => $this->participant->name,
                'initials' => $this->participant->initials(),
                'avatar' => $this->participant->avatarUrl(),
                'is_guest' => $this->participant->isGuest(),
            ] : null,
            'parent' => $this->parent ? [
                'uuid' => $this->parent->uuid,
                'author' => $this->parent->participant?->name,
                'excerpt' => $this->parent->trashed() ? null : Str::limit((string) $this->parent->body, 120),
            ] : null,
            'reactions' => $deleted ? [] : $this->reactions->groupBy('emoji')->map(fn ($group, $emoji) => [
                'emoji' => (string) $emoji,
                'count' => $group->count(),
                'participants' => $group->map(fn ($reaction) => ['uuid' => $reaction->participant->uuid, 'name' => $reaction->participant->name])->values()->all(),
            ])->values()->all(),
            'files' => $deleted ? [] : CallFileResource::collection($this->files)->resolve(),
            'created_at' => $this->created_at?->toISOString(),
            'edited_at' => $this->edited_at?->toISOString(),
        ];
    }
}
