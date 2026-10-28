<?php

namespace App\Services\Mail\Providers\Imap\Data;

use Carbon\CarbonInterface;

/**
 * A message fetched by UID: raw RFC 5322 source plus the server-side flags.
 */
final readonly class RemoteMessage
{
    /**
     * @param  array<int, string>  $flags  e.g. ["\Seen", "\Flagged"]
     */
    public function __construct(
        public int $uid,
        public string $raw,
        public array $flags = [],
        public ?CarbonInterface $internalDate = null,
        public ?int $size = null,
    ) {}
}
