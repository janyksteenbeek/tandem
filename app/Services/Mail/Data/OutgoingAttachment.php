<?php

namespace App\Services\Mail\Data;

final readonly class OutgoingAttachment
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $disk,
        public string $path,
        public ?string $contentId = null,
        public bool $isInline = false,
    ) {}
}
