<?php

namespace App\Services\Mail\Providers\Imap;

use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use App\Services\Mail\Exceptions\ProviderException;
use App\Services\Mail\Exceptions\TransientProviderException;
use App\Services\Mail\Providers\Imap\Data\RemoteFolder;
use App\Services\Mail\Providers\Imap\Data\RemoteFolderStatus;
use App\Services\Mail\Providers\Imap\Data\RemoteMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\RuntimeException as ImapRuntimeException;
use Webklex\PHPIMAP\IMAP;

/**
 * ImapSession backed by webklex/php-imap's pure PHP protocol implementation. Works at the
 * protocol level (UID commands) so we get raw RFC 5322 sources without side effects.
 */
class WebklexImapSession implements ImapSession
{
    protected ?string $selected = null;

    /** @var array<int, string>|null */
    protected ?array $capabilities = null;

    public function __construct(
        protected Client $client,
        protected string $host,
        protected int $port,
    ) {}

    /**
     * Open the connection and authenticate, translating failures into provider exceptions.
     */
    public function connect(): static
    {
        try {
            $this->client->connect();
        } catch (Throwable $e) {
            throw $this->translate($e);
        }

        return $this;
    }

    public function capabilities(): array
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }

        return $this->capabilities = $this->guard(function () {
            $data = $this->protocol()->getCapabilities()->validatedData();

            return array_values(array_filter(array_map('strval', is_array($data) ? $data : []), fn (string $c) => $c !== ''));
        });
    }

    public function folders(): array
    {
        return $this->guard(function () {
            $list = $this->protocol()->folders()->validatedData();
            $folders = [];

            foreach (is_array($list) ? $list : [] as $path => $meta) {
                if (! is_string($path) || ! is_array($meta)) {
                    continue;
                }

                $delimiter = (string) ($meta['delimiter'] ?? '/');
                $flags = array_values(array_map('strval', (array) ($meta['flags'] ?? [])));

                $folders[] = new RemoteFolder(path: $this->decodePath($path), delimiter: $delimiter === 'NIL' ? '' : $delimiter, flags: $flags);
            }

            return $folders;
        });
    }

    public function createFolder(string $path): void
    {
        $this->guard(fn () => $this->protocol()->createFolder($this->encodePath($path))->validatedData());
    }

    public function selectFolder(string $path): RemoteFolderStatus
    {
        return $this->guard(function () use ($path) {
            $data = $this->protocol()->selectFolder($this->encodePath($path))->validatedData();
            $this->selected = $path;

            return new RemoteFolderStatus(
                path: $path,
                uidValidity: (int) ($data['uidvalidity'] ?? 0),
                uidNext: (int) ($data['uidnext'] ?? 0),
                exists: (int) ($data['exists'] ?? 0),
            );
        });
    }

    public function uidsSince(string $path, CarbonInterface $since): array
    {
        return $this->search($path, ['SINCE', $since->format('d-M-Y')]);
    }

    public function uidsAfter(string $path, int $uid): array
    {
        $uids = $this->search($path, ['UID', ($uid + 1).':*']);

        return array_values(array_filter($uids, fn (int $candidate) => $candidate > $uid));
    }

    public function uidsByMessageId(string $path, string $messageId): array
    {
        $id = '<'.trim($messageId, " \t\r\n<>").'>';

        return $this->search($path, ['HEADER', 'Message-ID', $this->protocol()->escapeString($id)]);
    }

    public function fetchMessages(string $path, array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        return $this->guard(function () use ($path, $uids) {
            $this->ensureSelected($path);

            $items = ['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'BODY.PEEK[]'];
            $rows = $this->protocol()->fetch($items, array_values($uids), null, IMAP::ST_UID)->validatedData();
            $messages = [];

            foreach (is_array($rows) ? $rows : [] as $uid => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $raw = $row['BODY[]'] ?? $row['RFC822'] ?? null;

                if (! is_string($raw)) {
                    continue;
                }

                $messages[(int) $uid] = new RemoteMessage(
                    uid: (int) $uid,
                    raw: $raw,
                    flags: $this->flagList($row['FLAGS'] ?? []),
                    internalDate: $this->date($row['INTERNALDATE'] ?? null),
                    size: isset($row['RFC822.SIZE']) ? (int) $row['RFC822.SIZE'] : null,
                );
            }

            return $messages;
        });
    }

    public function fetchFlags(string $path, array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        return $this->guard(function () use ($path, $uids) {
            $this->ensureSelected($path);

            $rows = $this->protocol()->flags(array_values($uids), IMAP::ST_UID)->validatedData();
            $flags = [];

            foreach (is_array($rows) ? $rows : [] as $uid => $row) {
                $flags[(int) $uid] = $this->flagList($row);
            }

            return $flags;
        });
    }

    public function setFlags(string $path, array $uids, array $flags, bool $add): void
    {
        if ($uids === [] || $flags === []) {
            return;
        }

        $this->guard(function () use ($path, $uids, $flags, $add) {
            $this->ensureSelected($path);

            foreach ($this->ranges($uids) as [$from, $to]) {
                $this->protocol()->store($flags, $from, $to, $add ? '+' : '-', true, IMAP::ST_UID)->validatedData();
            }

            return null;
        });
    }

    public function move(string $path, array $uids, string $targetPath): array
    {
        if ($uids === []) {
            return [];
        }

        return $this->guard(function () use ($path, $uids, $targetPath) {
            $this->ensureSelected($path);
            $mapping = [];

            foreach ($this->ranges($uids) as [$from, $to]) {
                $response = $this->protocol()->moveMessage($this->encodePath($targetPath), $from, $to, IMAP::ST_UID);

                if (! $response->boolean()) {
                    throw new TransientProviderException(sprintf('The IMAP server refused to move messages to "%s".', $targetPath));
                }

                $mapping += $this->parseCopyUid($response);
            }

            return $mapping;
        });
    }

    public function append(string $path, string $raw, array $flags = []): ?int
    {
        return $this->guard(function () use ($path, $raw, $flags) {
            $response = $this->protocol()->appendMessage($this->encodePath($path), $raw, $flags === [] ? null : $flags);

            if (! $response->boolean()) {
                throw new TransientProviderException(sprintf('The IMAP server refused to append the message to "%s".', $path));
            }

            foreach ($this->lines($response) as $line) {
                if (preg_match('/\[APPENDUID\s+\d+\s+(\d+)\]/i', $line, $m)) {
                    return (int) $m[1];
                }
            }

            return null;
        });
    }

    public function idle(string $path, int $timeoutSeconds, Closure $shouldStop): bool
    {
        $protocol = $this->protocol();
        $deadline = CarbonImmutable::now()->addSeconds($timeoutSeconds);
        $changed = false;

        try {
            $this->ensureSelected($path, force: true);

            if (! in_array('IDLE', $this->capabilities(), true)) {
                throw new ProviderException('The IMAP server does not support IDLE.');
            }

            $protocol->idle();

            while (! $shouldStop() && CarbonImmutable::now()->lt($deadline)) {
                try {
                    $line = $protocol->nextLine(Response::empty());
                } catch (ImapRuntimeException $e) {
                    if (! $protocol->connected()) {
                        throw new TransientProviderException('The IMAP connection dropped while idling.', 0, $e);
                    }

                    continue;
                }

                if (preg_match('/^\*\s+\d+\s+(EXISTS|EXPUNGE)/i', $line) === 1) {
                    $changed = true;

                    break;
                }
            }

            $protocol->done();
        } catch (ProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->translate($e);
        }

        return $changed;
    }

    public function close(): void
    {
        try {
            $this->client->disconnect();
        } catch (Throwable) {
            // Closing is best-effort.
        }
    }

    /**
     * @param  array<int, string|array<int, string>>  $criteria
     * @return array<int, int>
     */
    protected function search(string $path, array $criteria): array
    {
        return $this->guard(function () use ($path, $criteria) {
            $this->ensureSelected($path);

            $ids = $this->protocol()->search($criteria, IMAP::ST_UID)->validatedData();
            $uids = array_map('intval', array_filter(is_array($ids) ? $ids : [], fn ($id) => is_numeric($id)));
            sort($uids);

            return array_values(array_unique($uids));
        });
    }

    protected function ensureSelected(string $path, bool $force = false): void
    {
        if ($force || $this->selected !== $path) {
            $this->selectFolder($path);
        }
    }

    protected function protocol(): ImapProtocol
    {
        $connection = $this->client->getConnection();

        if (! $connection instanceof ImapProtocol) {
            throw new ProviderException('The IMAP adapter requires the native protocol implementation.');
        }

        return $connection;
    }

    /**
     * Run a protocol interaction and translate library exceptions.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function guard(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->translate($e);
        }
    }

    /**
     * Human readable, actionable error messages for the UI and the sync job.
     */
    protected function translate(Throwable $e): ProviderException
    {
        $chain = [];

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $chain[] = $current->getMessage();
        }

        $detail = strtolower(implode(' | ', $chain));
        $endpoint = sprintf('%s:%d', $this->host, $this->port);

        if ($e instanceof AuthFailedException || str_contains($detail, 'authenticat') || str_contains($detail, 'login failed') || str_contains($detail, 'invalid credentials')) {
            return new AuthenticationRequiredException('The IMAP server rejected the username or password.', 0, $e);
        }

        if (str_contains($detail, 'certificate') || str_contains($detail, 'ssl') || str_contains($detail, 'tls') || str_contains($detail, 'crypto')) {
            return new TransientProviderException(sprintf('Could not establish a secure connection to %s. Check the encryption setting (SSL/TLS vs STARTTLS) and the certificate.', $endpoint), 0, $e);
        }

        if ($e instanceof ConnectionFailedException || str_contains($detail, 'refused') || str_contains($detail, 'timed out') || str_contains($detail, 'getaddrinfo') || str_contains($detail, 'connection')) {
            return new TransientProviderException(sprintf('Could not reach the IMAP server at %s: %s.', $endpoint, $this->leaf($chain)), 0, $e);
        }

        return new TransientProviderException(sprintf('IMAP error at %s: %s', $endpoint, $this->leaf($chain)), 0, $e);
    }

    /**
     * @param  array<int, string>  $chain
     */
    protected function leaf(array $chain): string
    {
        $messages = array_values(array_filter($chain, fn (string $m) => trim($m) !== ''));

        return $messages === [] ? 'unknown error' : rtrim(end($messages), '.');
    }

    /**
     * @return array<int, string>
     */
    protected function flagList(mixed $flags): array
    {
        if (is_string($flags)) {
            $flags = preg_split('/\s+/', trim($flags)) ?: [];
        }

        return array_values(array_filter(array_map('strval', is_array($flags) ? $flags : []), fn (string $f) => $f !== ''));
    }

    protected function date(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Collapse a UID list into contiguous [from, to] ranges to keep commands short.
     *
     * @param  array<int, int>  $uids
     * @return array<int, array{0: int, 1: int}>
     */
    protected function ranges(array $uids): array
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids);

        $ranges = [];
        $start = null;
        $previous = null;

        foreach ($uids as $uid) {
            if ($start === null) {
                $start = $previous = $uid;

                continue;
            }

            if ($uid === $previous + 1) {
                $previous = $uid;

                continue;
            }

            $ranges[] = [$start, $previous];
            $start = $previous = $uid;
        }

        if ($start !== null) {
            $ranges[] = [$start, $previous];
        }

        return $ranges;
    }

    /**
     * @return array<int, int>
     */
    protected function parseCopyUid(Response $response): array
    {
        foreach ($this->lines($response) as $line) {
            if (preg_match('/\[COPYUID\s+\d+\s+([\d:,]+)\s+([\d:,]+)\]/i', $line, $m)) {
                $source = $this->expandSet($m[1]);
                $target = $this->expandSet($m[2]);

                if (count($source) === count($target)) {
                    return array_combine($source, $target);
                }
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function lines(Response $response): array
    {
        $lines = [];

        foreach ($response->getResponse() as $line) {
            $lines[] = is_array($line) ? implode(' ', array_map(fn ($t) => is_array($t) ? implode(' ', $t) : (string) $t, $line)) : (string) $line;
        }

        foreach ((array) $response->data() as $line) {
            if (is_string($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * "1,3:5" => [1, 3, 4, 5]
     *
     * @return array<int, int>
     */
    protected function expandSet(string $set): array
    {
        $uids = [];

        foreach (explode(',', $set) as $part) {
            if (str_contains($part, ':')) {
                [$from, $to] = array_map('intval', explode(':', $part, 2));

                for ($i = min($from, $to); $i <= max($from, $to); $i++) {
                    $uids[] = $i;
                }
            } elseif ($part !== '') {
                $uids[] = (int) $part;
            }
        }

        return $uids;
    }

    protected function encodePath(string $path): string
    {
        return mb_convert_encoding($path, 'UTF7-IMAP', 'UTF-8') ?: $path;
    }

    protected function decodePath(string $path): string
    {
        $decoded = mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP');

        return $decoded !== '' ? $decoded : $path;
    }
}
