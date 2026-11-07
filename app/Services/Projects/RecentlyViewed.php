<?php

namespace App\Services\Projects;

use App\Models\Organization;
use App\Models\Projects\RecentView;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class RecentlyViewed
{
    public const KEEP = 50;

    public function touch(User $user, Organization $organization, Model $subject): void
    {
        RecentView::query()->updateOrCreate(
            ['user_id' => $user->id, 'viewable_type' => $subject->getMorphClass(), 'viewable_id' => $subject->getKey()],
            ['organization_id' => $organization->id, 'viewed_at' => now()],
        );
    }

    /**
     * @return Collection<int, RecentView>
     */
    public function forUser(User $user, Organization $organization, int $limit = 8): Collection
    {
        return RecentView::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->with('viewable')
            ->orderByDesc('viewed_at')
            ->limit($limit)
            ->get()
            ->filter(fn (RecentView $view) => $view->viewable !== null)
            ->values();
    }

    public function prune(): int
    {
        $deleted = 0;

        RecentView::query()->select('user_id')->distinct()->pluck('user_id')->each(function (int $userId) use (&$deleted) {
            $cutoff = RecentView::query()->where('user_id', $userId)->orderByDesc('viewed_at')->skip(self::KEEP)->value('viewed_at');

            if ($cutoff) {
                $deleted += RecentView::query()->where('user_id', $userId)->where('viewed_at', '<', $cutoff)->delete();
            }
        });

        return $deleted;
    }
}
