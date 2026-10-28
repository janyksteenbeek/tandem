<?php

namespace App\Services\Mail\Data;

use App\Enums\FolderType;

final readonly class FolderInfo
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $providerId,
        public string $name,
        public FolderType $type = FolderType::Custom,
        public bool $isSelectable = true,
        public array $attributes = [],
    ) {}
}
