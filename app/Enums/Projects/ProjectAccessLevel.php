<?php

namespace App\Enums\Projects;

/**
 * How much a user may do with a product, project, issue or workstream. Resolved centrally by
 * IssueAccess; policies only compare levels.
 */
enum ProjectAccessLevel: int
{
    case Viewer = 1;
    case Participant = 2;
    case Manager = 3;
    case Admin = 4;

    public function isAtLeast(self $level): bool
    {
        return $this->value >= $level->value;
    }

    /**
     * @param  array<int, self|null>  $levels
     */
    public static function highest(array $levels): ?self
    {
        $best = null;

        foreach ($levels as $level) {
            if ($level && (! $best || $level->value > $best->value)) {
                $best = $level;
            }
        }

        return $best;
    }
}
