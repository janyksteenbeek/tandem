<?php

namespace App\Enums;

enum InboxRole: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function level(): int
    {
        return match ($this) {
            self::Admin => 3,
            self::Agent => 2,
            self::Viewer => 1,
        };
    }

    public function isAtLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()])
            ->all();
    }
}
