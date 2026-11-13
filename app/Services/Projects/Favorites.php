<?php

namespace App\Services\Projects;

use App\Models\Organization;
use App\Models\Projects\Favorite;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Favorites
{
    public function toggle(User $user, Organization $organization, Model $subject): bool
    {
        $existing = Favorite::query()
            ->where('user_id', $user->id)
            ->where('favoritable_type', $subject->getMorphClass())
            ->where('favoritable_id', $subject->getKey())
            ->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        Favorite::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'favoritable_type' => $subject->getMorphClass(),
            'favoritable_id' => $subject->getKey(),
            'position' => (int) Favorite::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->max('position') + 1,
        ]);

        return true;
    }

    /**
     * @return Collection<int, Favorite>
     */
    public function forUser(User $user, Organization $organization): Collection
    {
        return Favorite::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->with('favoritable')
            ->orderBy('position')
            ->get()
            ->filter(fn (Favorite $favorite) => $favorite->favoritable !== null)
            ->values();
    }

    public function isFavorite(User $user, Model $subject): bool
    {
        return Favorite::query()
            ->where('user_id', $user->id)
            ->where('favoritable_type', $subject->getMorphClass())
            ->where('favoritable_id', $subject->getKey())
            ->exists();
    }
}
