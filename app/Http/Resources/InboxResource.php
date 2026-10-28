<?php

namespace App\Http\Resources;

use App\Models\Inbox;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Inbox
 */
class InboxResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'name' => $this->name,
            'slug' => $this->slug,
            'color' => $this->color,
            'icon' => $this->icon,
            'description' => $this->description,
            'email_address' => $this->email_address,
            'organization_id' => $this->organization_id,
            'role' => $user ? $this->roleFor($user)?->value : null,
            'accounts' => $this->whenLoaded('mailAccounts', fn () => $this->mailAccounts->map(fn ($account) => [
                'uuid' => $account->uuid,
                'provider' => $account->provider->value,
                'email' => $account->email_address,
                'display_name' => $account->display_name,
                'status' => $account->status->value,
                'last_synced_at' => $account->last_synced_at?->toISOString(),
                'last_error' => $account->last_error,
                'identities' => $account->relationLoaded('identities') ? $account->identities->map(fn ($identity) => [
                    'id' => $identity->id,
                    'name' => $identity->name,
                    'email' => $identity->email,
                    'is_default' => $identity->is_default,
                    'signature_html' => $identity->signature_html,
                ])->values() : [],
            ])->values()),
        ];
    }
}
