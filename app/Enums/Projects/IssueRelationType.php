<?php

namespace App\Enums\Projects;

enum IssueRelationType: string
{
    case Blocks = 'blocks';
    case Related = 'related';
    case Duplicate = 'duplicate';

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
