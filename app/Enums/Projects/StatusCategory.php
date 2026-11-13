<?php

namespace App\Enums\Projects;

/**
 * The standardized meaning behind every workflow status. Teams name and colour their own
 * statuses, but reporting, completion rules and filters only ever look at the category.
 */
enum StatusCategory: string
{
    case Backlog = 'backlog';
    case Unstarted = 'unstarted';
    case Started = 'started';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Backlog => 'Backlog',
            self::Unstarted => 'Todo',
            self::Started => 'In progress',
            self::Completed => 'Done',
            self::Canceled => 'Canceled',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Canceled;
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Ordering used when sorting by status and when deciding whether a transition moves forward.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Backlog => 0,
            self::Unstarted => 1,
            self::Started => 2,
            self::Completed => 3,
            self::Canceled => 4,
        };
    }

    /**
     * Default colour for statuses created in this category.
     */
    public function color(): string
    {
        return match ($this) {
            self::Backlog => 'slate',
            self::Unstarted => 'sky',
            self::Started => 'amber',
            self::Completed => 'violet',
            self::Canceled => 'rose',
        };
    }

    /**
     * Categories every workflow must keep at least one status in.
     *
     * @return array<int, self>
     */
    public static function required(): array
    {
        return [self::Unstarted, self::Started, self::Completed, self::Canceled];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
