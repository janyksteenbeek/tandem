<?php

namespace App\Enums\Projects;

enum IssuePriority: int
{
    case None = 0;
    case Low = 1;
    case Medium = 2;
    case High = 3;
    case Urgent = 4;

    public function key(): string
    {
        return strtolower($this->name);
    }

    public static function fromKey(string $key): self
    {
        foreach (self::cases() as $case) {
            if ($case->key() === strtolower($key)) {
                return $case;
            }
        }

        return self::None;
    }

    public static function tryFromKey(?string $key): ?self
    {
        if ($key === null) {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($case->key() === strtolower($key)) {
                return $case;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'No priority',
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $p) => ['value' => $p->key(), 'label' => $p->label()], self::cases());
    }
}
