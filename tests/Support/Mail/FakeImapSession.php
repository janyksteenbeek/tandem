<?php

namespace Tests\Support\Mail;

use App\Services\Mail\Providers\Imap\Data\RemoteFolder;
use App\Services\Mail\Providers\Imap\Data\RemoteFolderStatus;
use App\Services\Mail\Providers\Imap\Data\RemoteMessage;
use App\Services\Mail\Providers\Imap\ImapSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use RuntimeException;

/**
 * In-memory IMAP server used by the adapter tests. Folders hold messages keyed by UID.
 */
class FakeImapSession implements ImapSession
{
    /**
     * @var array<string, array{flags: array<int, string>, uidValidity: int, uidNext: int, messages: array<int, array{raw: string, flags: array<int, string>, date: CarbonInterface}>}>
     */
    public array $folders = [];

    /** @var array<int, string> */
    public array $capabilities = ['IMAP4rev1', 'IDLE', 'MOVE', 'UIDPLUS'];

    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public bool $idleResult = false;

    public bool $closed = false;

    /**
     * @param  array<int, string>  $flags
     */
    public function addFolder(string $path, array $flags = [], int $uidValidity = 1): static
    {
        $this->folders[$path] = ['flags' => $flags, 'uidValidity' => $uidValidity, 'uidNext' => 1, 'messages' => []];

        return $this;
    }

    /**
     * @param  array<int, string>  $flags
     */
    public function addMessage(string $path, int $uid, string $raw, array $flags = [], ?CarbonInterface $date = null): static
    {
        $this->folders[$path]['messages'][$uid] = ['raw' => $raw, 'flags' => $flags, 'date' => $date ?? CarbonImmutable::now()];
        $this->folders[$path]['uidNext'] = max($this->folders[$path]['uidNext'], $uid + 1);
        ksort($this->folders[$path]['messages']);

        return $this;
    }

    public function removeMessage(string $path, int $uid): static
    {
        unset($this->folders[$path]['messages'][$uid]);

        return $this;
    }

    /**
     * @param  array<int, string>  $flags
     */
    public function replaceFlags(string $path, int $uid, array $flags): static
    {
        $this->folders[$path]['messages'][$uid]['flags'] = $flags;

        return $this;
    }

    /**
     * Simulate a server rebuilding its index: new UIDVALIDITY and renumbered UIDs.
     */
    public function resetUidValidity(string $path, int $uidValidity): static
    {
        $messages = array_values($this->folders[$path]['messages']);
        $this->folders[$path] = ['flags' => $this->folders[$path]['flags'], 'uidValidity' => $uidValidity, 'uidNext' => 1, 'messages' => []];

        foreach ($messages as $index => $message) {
            $this->folders[$path]['messages'][$index + 1] = $message;
            $this->folders[$path]['uidNext'] = $index + 2;
        }

        return $this;
    }

    public function calls(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call) => $call[0] === $method));
    }

    public function capabilities(): array
    {
        $this->calls[] = ['capabilities', []];

        return $this->capabilities;
    }

    public function folders(): array
    {
        $this->calls[] = ['folders', []];

        return array_map(fn (string $path) => new RemoteFolder($path, '/', $this->folders[$path]['flags']), array_keys($this->folders));
    }

    public function createFolder(string $path): void
    {
        $this->calls[] = ['createFolder', [$path]];
        $this->addFolder($path, ['\HasNoChildren'], 500 + count($this->folders));
    }

    public function selectFolder(string $path): RemoteFolderStatus
    {
        $this->calls[] = ['selectFolder', [$path]];
        $folder = $this->folder($path);

        return new RemoteFolderStatus($path, $folder['uidValidity'], $folder['uidNext'], count($folder['messages']));
    }

    public function uidsSince(string $path, CarbonInterface $since): array
    {
        $this->calls[] = ['uidsSince', [$path, $since]];

        return array_keys(array_filter($this->folder($path)['messages'], fn (array $m) => $m['date']->greaterThanOrEqualTo($since->startOfDay())));
    }

    public function uidsAfter(string $path, int $uid): array
    {
        $this->calls[] = ['uidsAfter', [$path, $uid]];

        return array_values(array_filter(array_keys($this->folder($path)['messages']), fn (int $candidate) => $candidate > $uid));
    }

    public function uidsByMessageId(string $path, string $messageId): array
    {
        $this->calls[] = ['uidsByMessageId', [$path, $messageId]];
        $needle = strtolower(trim($messageId, '<>'));

        return array_keys(array_filter($this->folder($path)['messages'], fn (array $m) => str_contains(strtolower($m['raw']), '<'.$needle.'>')));
    }

    public function fetchMessages(string $path, array $uids): array
    {
        $this->calls[] = ['fetchMessages', [$path, $uids]];
        $messages = [];

        foreach ($uids as $uid) {
            if (isset($this->folder($path)['messages'][$uid])) {
                $m = $this->folder($path)['messages'][$uid];
                $messages[$uid] = new RemoteMessage($uid, $m['raw'], $m['flags'], $m['date'], strlen($m['raw']));
            }
        }

        return $messages;
    }

    public function fetchFlags(string $path, array $uids): array
    {
        $this->calls[] = ['fetchFlags', [$path, $uids]];
        $flags = [];

        foreach ($uids as $uid) {
            if (isset($this->folder($path)['messages'][$uid])) {
                $flags[$uid] = $this->folder($path)['messages'][$uid]['flags'];
            }
        }

        return $flags;
    }

    public function setFlags(string $path, array $uids, array $flags, bool $add): void
    {
        $this->calls[] = ['setFlags', [$path, $uids, $flags, $add]];

        foreach ($uids as $uid) {
            $current = $this->folder($path)['messages'][$uid]['flags'] ?? [];
            $this->folders[$path]['messages'][$uid]['flags'] = $add
                ? array_values(array_unique([...$current, ...$flags]))
                : array_values(array_diff($current, $flags));
        }
    }

    public function move(string $path, array $uids, string $targetPath): array
    {
        $this->calls[] = ['move', [$path, $uids, $targetPath]];
        $this->folder($targetPath);
        $mapping = [];

        foreach ($uids as $uid) {
            $message = $this->folder($path)['messages'][$uid] ?? null;

            if ($message === null) {
                continue;
            }

            $newUid = $this->folders[$targetPath]['uidNext'];
            $this->folders[$targetPath]['messages'][$newUid] = $message;
            $this->folders[$targetPath]['uidNext'] = $newUid + 1;
            unset($this->folders[$path]['messages'][$uid]);
            $mapping[$uid] = $newUid;
        }

        return $mapping;
    }

    public function append(string $path, string $raw, array $flags = []): ?int
    {
        $this->calls[] = ['append', [$path, $raw, $flags]];
        $uid = $this->folder($path)['uidNext'];
        $this->addMessage($path, $uid, $raw, $flags);

        return $uid;
    }

    public function idle(string $path, int $timeoutSeconds, Closure $shouldStop): bool
    {
        $this->calls[] = ['idle', [$path, $timeoutSeconds]];

        return $this->idleResult;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @return array{flags: array<int, string>, uidValidity: int, uidNext: int, messages: array<int, array{raw: string, flags: array<int, string>, date: CarbonInterface}>}
     */
    protected function folder(string $path): array
    {
        return $this->folders[$path] ?? throw new RuntimeException("Fake folder [{$path}] does not exist.");
    }
}
