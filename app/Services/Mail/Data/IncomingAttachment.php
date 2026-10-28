<?php

namespace App\Services\Mail\Data;

final readonly class IncomingAttachment
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public int $size,
        public ?string $content = null,
        public ?string $contentId = null,
        public bool $isInline = false,
        public ?string $providerAttachmentId = null,
    ) {}

    public function hasContent(): bool
    {
        return $this->content !== null;
    }
}
