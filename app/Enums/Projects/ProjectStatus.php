<?php

namespace App\Enums\Projects;

enum ProjectStatus: string
{
    case Backlog = 'backlog';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
