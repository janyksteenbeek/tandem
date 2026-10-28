<?php

namespace App\Services\Mail\Providers\Imap\Data;

/**
 * Result of SELECT / EXAMINE on a folder.
 */
final readonly class RemoteFolderStatus
{
    public function __construct(
        public string $path,
        public int $uidValidity,
        public int $uidNext,
        public int $exists = 0,
    ) {}
}
