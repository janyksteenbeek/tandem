<?php

namespace App\Enums\Projects;

enum SharedContentKind: string
{
    case Excerpt = 'excerpt';
    case Attachment = 'attachment';

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
