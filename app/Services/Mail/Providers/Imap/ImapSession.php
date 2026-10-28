<?php

namespace App\Services\Mail\Providers\Imap;

use App\Services\Mail\Providers\Imap\Data\RemoteFolder;
use App\Services\Mail\Providers\Imap\Data\RemoteFolderStatus;
use App\Services\Mail\Providers\Imap\Data\RemoteMessage;
use Carbon\CarbonInterface;
use Closure;

/**
 * Thin, authenticated IMAP connection. This is the only surface the IMAP adapter talks to,
 * so the protocol library can be swapped and tests can use in-memory fakes.
 *
 * Implementations translate library errors into App\Services\Mail\Exceptions\* exceptions.
 */
interface ImapSession
{
    /**
     * @return array<int, string>
     */
    public function capabilities(): array;

    /**
     * @return array<int, RemoteFolder>
     */
    public function folders(): array;

    public function createFolder(string $path): void;

    public function selectFolder(string $path): RemoteFolderStatus;

    /**
     * UIDs of messages received on or after the given date (IMAP SEARCH SINCE), ascending.
     *
     * @return array<int, int>
     */
    public function uidsSince(string $path, CarbonInterface $since): array;

    /**
     * UIDs strictly greater than the given UID, ascending.
     *
     * @return array<int, int>
     */
    public function uidsAfter(string $path, int $uid): array;

    /**
     * UIDs of messages in the folder carrying the given Message-ID header.
     *
     * @return array<int, int>
     */
    public function uidsByMessageId(string $path, string $messageId): array;

    /**
     * Fetch raw source + flags for the given UIDs without marking them as read.
     *
     * @param  array<int, int>  $uids
     * @return array<int, RemoteMessage> Keyed by UID. Missing UIDs are omitted.
     */
    public function fetchMessages(string $path, array $uids): array;

    /**
     * @param  array<int, int>  $uids
     * @return array<int, array<int, string>> UID => flags. UIDs that no longer exist are omitted.
     */
    public function fetchFlags(string $path, array $uids): array;

    /**
     * @param  array<int, int>  $uids
     * @param  array<int, string>  $flags
     */
    public function setFlags(string $path, array $uids, array $flags, bool $add): void;

    /**
     * Move messages to another folder.
     *
     * @param  array<int, int>  $uids
     * @return array<int, int> Old UID => new UID when the server reported COPYUID, otherwise empty.
     */
    public function move(string $path, array $uids, string $targetPath): array;

    /**
     * Append a raw message to a folder.
     *
     * @param  array<int, string>  $flags
     * @return int|null The new UID when the server reported APPENDUID.
     */
    public function append(string $path, string $raw, array $flags = []): ?int;

    /**
     * Block in IDLE on the given folder until the server reports a change, the timeout passes,
     * or $shouldStop returns true. Returns true when the mailbox changed.
     *
     * @param  Closure(): bool  $shouldStop
     */
    public function idle(string $path, int $timeoutSeconds, Closure $shouldStop): bool;

    public function close(): void;
}
