<?php

namespace App\Services\Automations;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Picks the team member a conversation should be handed to.
 */
class TeamAssigner
{
    public const ROUND_ROBIN = 'round_robin';

    public const LEAST_BUSY = 'least_busy';

    /** @var array<int, string> */
    public const STRATEGIES = [self::ROUND_ROBIN, self::LEAST_BUSY];

    public function pick(Team $team, string $strategy, Conversation $conversation): ?User
    {
        $members = $team->members()->orderBy('users.id')->get();

        if ($members->isEmpty()) {
            return null;
        }

        return $strategy === self::LEAST_BUSY
            ? $this->leastBusy($members->all(), $conversation)
            : $this->roundRobin($team, $members->all());
    }

    /**
     * @param  array<int, User>  $members
     */
    protected function roundRobin(Team $team, array $members): User
    {
        $key = sprintf('automations:team:%d:round_robin', $team->id);

        Cache::add($key, 0);
        $counter = (int) Cache::increment($key);

        return $members[($counter - 1) % count($members)];
    }

    /**
     * @param  array<int, User>  $members
     */
    protected function leastBusy(array $members, Conversation $conversation): User
    {
        $ids = array_map(fn (User $user) => $user->id, $members);

        $counts = Conversation::query()
            ->where('organization_id', $conversation->organization_id)
            ->whereIn('assignee_id', $ids)
            ->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::Pending->value])
            ->where('is_trashed', false)
            ->selectRaw('assignee_id, COUNT(*) as aggregate')
            ->groupBy('assignee_id')
            ->pluck('aggregate', 'assignee_id');

        usort($members, fn (User $a, User $b) => [(int) ($counts[$a->id] ?? 0), $a->id] <=> [(int) ($counts[$b->id] ?? 0), $b->id]);

        return $members[0];
    }
}
