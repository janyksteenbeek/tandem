<?php

namespace App\Enums;

enum ConversationPriority: int
{
    case None = 0;
    case Low = 1;
    case Normal = 2;
    case High = 3;
    case Urgent = 4;

    public function label(): string
    {
        return match ($this) {
            self::None => 'No priority',
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

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

    /**
     * @return array<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $priority) => ['value' => $priority->key(), 'label' => $priority->label()])
            ->all();
    }
}
