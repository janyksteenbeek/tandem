<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\UserSummaryResource;
use App\Models\Projects\IssueActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IssueActivity
 */
class IssueActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['actor', 'workstream.team']);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'payload' => $this->payload ?? [],
            'actor' => $this->actor ? UserSummaryResource::make($this->actor)->resolve() : null,
            'team' => $this->workstream?->team ? TeamSummaryResource::make($this->workstream->team)->resolve() : null,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
