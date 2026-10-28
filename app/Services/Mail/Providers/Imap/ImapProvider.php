<?php

namespace App\Services\Mail\Providers\Imap;

use App\Enums\FolderType;
use App\Enums\MailProvider as ProviderType;
use App\Models\Conversation;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\Message;
use App\Models\SyncRun;
use App\Services\Mail\Data\ConnectionTestResult;
use App\Services\Mail\Data\FolderInfo;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Data\SentMessageResult;
use App\Services\Mail\Data\SyncResult;
use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use App\Services\Mail\Exceptions\ProviderException;
use App\Services\Mail\Exceptions\TransientProviderException;
use App\Services\Mail\Ingestion\IngestResult;
use App\Services\Mail\Ingestion\MessageIngestor;
use App\Services\Mail\Ingestion\MimeMessageParser;
use App\Services\Mail\Outbound\SymfonyEmailBuilder;
use App\Services\Mail\Providers\AbstractMailProvider;
use App\Services\Mail\Providers\Imap\Data\RemoteFolderStatus;
use App\Services\Mail\Providers\Imap\Data\RemoteMessage;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * IMAP for reading and flag/folder write-back, SMTP for sending. Every message is identified by
 * "{folder}:{uidvalidity}:{uid}" and ingested through the shared MIME parser + ingestor, so
 * nothing here knows about conversations beyond recomputing their flags.
 */
class ImapProvider extends AbstractMailProvider
{
    /**
     * How far back incremental syncs re-check flags and deletions.
     */
    public const int FLAG_REFRESH_DAYS = 7;

    public function __construct(
        protected ImapClientFactory $clients,
        protected SmtpTransportFactory $transports,
        protected ImapFolderMapper $mapper,
        protected MimeMessageParser $parser,
        protected MessageIngestor $ingestor,
        protected SymfonyEmailBuilder $emails,
    ) {}

    public function type(): ProviderType
    {
        return ProviderType::Imap;
    }

    public function testConnection(MailAccount $account): ConnectionTestResult
    {
        $password = $account->credential('password');

        if (! is_string($password) || $password === '') {
            return ConnectionTestResult::failure('No IMAP password is stored for this account.', ['stage' => 'imap']);
        }

        $imap = (array) $account->setting('imap', []);
        $imap['username'] ??= $account->email_address;

        $smtp = (array) $account->setting('smtp', []);
        $smtpPassword = $account->credential('smtp_password');

        return $this->testSettings($imap, $smtp === [] ? null : $smtp, $password, is_string($smtpPassword) ? $smtpPassword : null);
    }

    /**
     * Verify IMAP (and optionally SMTP) settings without touching any model.
     *
     * @param  array<string, mixed>  $imap
     * @param  array<string, mixed>|null  $smtp
     */
    public function testSettings(array $imap, ?array $smtp, string $password, ?string $smtpPassword = null): ConnectionTestResult
    {
        try {
            $session = $this->clients->makeFromSettings($imap, $password);

            try {
                $capabilities = $session->capabilities();
                $folders = $session->folders();
            } finally {
                $session->close();
            }
        } catch (ProviderException $e) {
            return ConnectionTestResult::failure($e->getMessage(), ['stage' => 'imap']);
        }

        $details = [
            'stage' => 'imap',
            'folders' => count($folders),
            'capabilities' => $capabilities,
            'idle' => in_array('IDLE', $capabilities, true),
        ];

        if ($smtp !== null && ($smtp['host'] ?? null)) {
            $smtp['username'] = $smtp['username'] ?? $imap['username'] ?? null;

            try {
                $transport = $this->transports->makeFromSettings($smtp, $smtpPassword ?? $password);

                if ($transport instanceof SmtpTransport) {
                    $transport->start();
                    $transport->stop();
                }
            } catch (TransportExceptionInterface $e) {
                return ConnectionTestResult::failure($this->translateSmtp($e, $smtp)->getMessage(), ['stage' => 'smtp'] + $details);
            } catch (ProviderException $e) {
                return ConnectionTestResult::failure($e->getMessage(), ['stage' => 'smtp'] + $details);
            }

            $details['smtp'] = true;
        }

        return ConnectionTestResult::success(sprintf('Connected. Found %d folders.', count($folders)), $details);
    }

    /**
     * @return Collection<int, FolderInfo>
     */
    public function discoverFolders(MailAccount $account): Collection
    {
        return $this->withSession($account, fn (ImapSession $session) => collect($session->folders())
            ->map(fn ($folder) => $this->mapper->toFolderInfo($folder))
            ->values());
    }

    /**
     * Upsert MailFolder rows from the server's folder list. Folders that disappeared remotely are
     * disabled, never deleted, because messages reference them.
     *
     * @return Collection<int, MailFolder>
     */
    public function syncFolders(MailAccount $account, ?ImapSession $session = null): Collection
    {
        $remote = $session
            ? $session->folders()
            : $this->withSession($account, fn (ImapSession $owned) => $owned->folders());

        $seen = [];

        foreach ($remote as $remoteFolder) {
            $info = $this->mapper->toFolderInfo($remoteFolder);

            $folder = MailFolder::query()->firstOrNew([
                'mail_account_id' => $account->id,
                'provider_id' => $info->providerId,
            ]);

            $folder->fill([
                'name' => $info->name,
                'type' => $info->type,
                'attributes' => $info->attributes,
                'is_selectable' => $info->isSelectable,
            ]);

            $syncByDefault = (bool) ($info->attributes['sync_enabled'] ?? true);

            if (! $folder->exists) {
                $folder->sync_enabled = $syncByDefault;
            } elseif (! $syncByDefault) {
                $folder->sync_enabled = false;
            }

            $folder->save();
            $seen[] = $folder->id;
        }

        MailFolder::query()
            ->where('mail_account_id', $account->id)
            ->whereNotIn('id', $seen)
            ->update(['sync_enabled' => false, 'is_selectable' => false]);

        return $account->folders()->get();
    }

    public function initialSync(MailAccount $account, SyncRun $run): SyncResult
    {
        $result = new SyncResult;
        $since = now()->subDays($this->initialSyncDays());

        $this->withSession($account, function (ImapSession $session) use ($account, $result, $since) {
            $folders = $this->syncableFolders($this->syncFolders($account, $session));

            foreach ($folders as $folder) {
                $status = $session->selectFolder($folder->provider_id);
                $this->reconcileUidValidity($folder, $status);

                $pending = $this->pendingUids($session->uidsSince($folder->provider_id, $since), $folder);
                $this->syncPendingUids($account, $session, $folder, $status, $pending, $result);
            }
        });

        if (! $result->hasMore && $account->initial_sync_completed_at === null) {
            $account->forceFill(['initial_sync_completed_at' => now()])->save();
        }

        return $result;
    }

    public function incrementalSync(MailAccount $account, SyncRun $run): SyncResult
    {
        $result = new SyncResult;
        $since = now()->subDays($this->initialSyncDays());

        $this->withSession($account, function (ImapSession $session) use ($account, $result, $since) {
            $folders = $this->syncableFolders($account->folders()->get());

            if ($folders->isEmpty()) {
                $folders = $this->syncableFolders($this->syncFolders($account, $session));
            }

            foreach ($folders as $folder) {
                $status = $session->selectFolder($folder->provider_id);
                $reset = $this->reconcileUidValidity($folder, $status);

                $uids = $reset || $folder->last_synced_uid === null
                    ? $session->uidsSince($folder->provider_id, $since)
                    : $session->uidsAfter($folder->provider_id, (int) $folder->last_synced_uid);

                $this->syncPendingUids($account, $session, $folder, $status, $this->pendingUids($uids, $folder), $result);
                $this->refreshRecentFlags($account, $session, $folder, $result);
            }
        });

        return $result;
    }

    public function send(MailAccount $account, OutgoingMessage $message): SentMessageResult
    {
        $email = $this->emails->build($message);
        $messageId = $message->messageId ? trim($message->messageId, " \t\r\n<>") : $this->generateMessageId($message->from->email);

        if (! $message->messageId) {
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        }

        try {
            $sent = $this->transports->make($account)->send($email);
        } catch (TransportExceptionInterface $e) {
            throw $this->translateSmtp($e, (array) $account->setting('smtp', []));
        }

        $providerMessageId = null;

        if ($sent && $account->setting('smtp.append_sent') !== false) {
            try {
                $providerMessageId = $this->appendToSent($account, $sent->toString());
            } catch (Throwable $e) {
                Log::warning('Could not append sent message to the IMAP Sent folder', ['account_id' => $account->id, 'error' => $e->getMessage()]);
            }
        }

        return new SentMessageResult($messageId, $providerMessageId);
    }

    public function markRead(MailAccount $account, Collection $messages, bool $read): void
    {
        $this->storeFlags($account, $messages, '\Seen', $read);
    }

    public function setStarred(MailAccount $account, Collection $messages, bool $starred): void
    {
        $this->storeFlags($account, $messages, '\Flagged', $starred);
    }

    public function archive(MailAccount $account, Collection $messages): void
    {
        $this->moveMessages($account, $messages, fn (ImapSession $session) => $this->ensureFolder($account, $session, FolderType::Archive, 'Archive'));
    }

    public function moveToInbox(MailAccount $account, Collection $messages): void
    {
        $this->moveMessages($account, $messages, fn (ImapSession $session) => $this->ensureFolder($account, $session, FolderType::Inbox, 'INBOX'));
    }

    public function trash(MailAccount $account, Collection $messages): void
    {
        $this->moveMessages($account, $messages, fn (ImapSession $session) => $this->ensureFolder($account, $session, FolderType::Trash, 'Trash'));
    }

    public function restore(MailAccount $account, Collection $messages): void
    {
        $this->moveToInbox($account, $messages);
    }

    public function markSpam(MailAccount $account, Collection $messages, bool $spam): void
    {
        $this->moveMessages($account, $messages, fn (ImapSession $session) => $spam
            ? $this->ensureFolder($account, $session, FolderType::Spam, 'Junk')
            : $this->ensureFolder($account, $session, FolderType::Inbox, 'INBOX'));
    }

    public function moveToFolder(MailAccount $account, Collection $messages, MailFolder $folder): void
    {
        if ($folder->mail_account_id !== $account->id) {
            throw new ProviderException('The target folder belongs to a different mail account.');
        }

        $this->moveMessages($account, $messages, fn () => $folder);
    }

    public function disconnect(MailAccount $account): void
    {
        // Passwords are simply dropped with the account; there is no server-side session to revoke.
    }

    /**
     * Open a session for the account, run the callback and always close the connection.
     *
     * @template T
     *
     * @param  Closure(ImapSession): T  $callback
     * @return T
     */
    protected function withSession(MailAccount $account, Closure $callback): mixed
    {
        $session = $this->clients->make($account);

        try {
            return $callback($session);
        } finally {
            $session->close();
        }
    }

    /**
     * @param  Collection<int, MailFolder>  $folders
     * @return Collection<int, MailFolder>
     */
    protected function syncableFolders(Collection $folders): Collection
    {
        return $folders
            ->filter(fn (MailFolder $folder) => $folder->sync_enabled && $folder->is_selectable)
            ->sortBy(fn (MailFolder $folder) => match ($folder->type) {
                FolderType::Inbox => 0,
                FolderType::Sent => 1,
                FolderType::Archive => 2,
                FolderType::Drafts => 3,
                FolderType::Spam => 8,
                FolderType::Trash => 9,
                default => 5,
            })
            ->values();
    }

    /**
     * Persist UIDVALIDITY / UIDNEXT. When UIDVALIDITY changed every stored UID is meaningless:
     * reset the cursor and forget local UIDs so the folder is re-synced (messages are matched
     * again by Message-ID, never duplicated).
     */
    protected function reconcileUidValidity(MailFolder $folder, RemoteFolderStatus $status): bool
    {
        $changed = $folder->uid_validity !== null
            && $status->uidValidity !== 0
            && (int) $folder->uid_validity !== $status->uidValidity;

        if ($changed) {
            Log::info('IMAP UIDVALIDITY changed, re-syncing folder', ['folder_id' => $folder->id, 'from' => $folder->uid_validity, 'to' => $status->uidValidity]);

            Message::withTrashed()->where('folder_id', $folder->id)->update(['imap_uid' => null]);
            $folder->last_synced_uid = 0;
        }

        $folder->forceFill([
            'uid_validity' => $status->uidValidity !== 0 ? $status->uidValidity : $folder->uid_validity,
            'uid_next' => $status->uidNext !== 0 ? $status->uidNext : $folder->uid_next,
            'message_count' => $status->exists,
        ])->save();

        return $changed;
    }

    /**
     * @param  array<int, int>  $uids
     * @return array<int, int>
     */
    protected function pendingUids(array $uids, MailFolder $folder): array
    {
        $cursor = (int) $folder->last_synced_uid;
        $pending = array_values(array_unique(array_filter(array_map('intval', $uids), fn (int $uid) => $uid > $cursor)));
        sort($pending);

        return $pending;
    }

    /**
     * Ingest one batch of the pending UIDs; flag the result as having more work when the batch did
     * not drain the folder, otherwise fast-forward the cursor to UIDNEXT so older mail outside the
     * sync window is never fetched later.
     *
     * @param  array<int, int>  $pending
     */
    protected function syncPendingUids(MailAccount $account, ImapSession $session, MailFolder $folder, RemoteFolderStatus $status, array $pending, SyncResult $result): void
    {
        $batchSize = $this->batchSize();
        $batch = array_slice($pending, 0, $batchSize);

        $this->ingestUids($account, $session, $folder, $batch, $result);

        if (count($pending) > $batchSize) {
            $result->hasMore = true;

            return;
        }

        $folder->forceFill([
            'last_synced_uid' => max((int) $folder->last_synced_uid, $status->uidNext - 1, 0),
            'last_synced_at' => now(),
        ])->save();
    }

    /**
     * @param  array<int, int>  $uids
     */
    protected function ingestUids(MailAccount $account, ImapSession $session, MailFolder $folder, array $uids, SyncResult $result): void
    {
        if ($uids === []) {
            return;
        }

        $messages = $session->fetchMessages($folder->provider_id, $uids);

        foreach ($uids as $uid) {
            $remote = $messages[$uid] ?? null;

            if ($remote === null) {
                $result->skipped++;

                continue;
            }

            try {
                $ingest = $this->ingestRemote($account, $folder, $remote);
                $result->fetched++;
                $ingest->created ? $result->created++ : $result->updated++;
            } catch (Throwable $e) {
                $result->skipped++;
                $result->errors[] = sprintf('%s uid %d: %s', $folder->provider_id, $uid, Str::limit($e->getMessage(), 200));
                Log::warning('IMAP message could not be ingested', ['account_id' => $account->id, 'folder' => $folder->provider_id, 'uid' => $uid, 'error' => $e->getMessage()]);
            }
        }

        $folder->forceFill([
            'last_synced_uid' => max((int) $folder->last_synced_uid, max($uids)),
            'last_synced_at' => now(),
        ])->save();
    }

    protected function ingestRemote(MailAccount $account, MailFolder $folder, RemoteMessage $remote): IngestResult
    {
        $providerMessageId = $this->providerMessageId($folder, $remote->uid);

        $incoming = $this->parser->parse($remote->raw, [
            'providerMessageId' => $providerMessageId,
            'imapUid' => $remote->uid,
            'folderId' => $folder->id,
            'labels' => [$folder->provider_id],
            'state' => $this->mapper->state($folder->type, $remote->flags),
            'receivedAt' => $remote->internalDate,
            'sizeBytes' => $remote->size ?? strlen($remote->raw),
        ]);

        $result = $this->ingestor->ingest($account, $incoming);

        $this->adoptProviderId($result->message, $providerMessageId, $folder, $remote->uid);

        return $result;
    }

    /**
     * A message found again under a new UID (moved server-side, UIDVALIDITY reset) keeps working
     * through its Message-ID, but the stored provider id should follow it.
     */
    protected function adoptProviderId(Message $message, string $providerMessageId, MailFolder $folder, ?int $uid): void
    {
        if ($message->provider_message_id === $providerMessageId && $message->folder_id === $folder->id && $message->imap_uid === $uid) {
            return;
        }

        $taken = Message::withTrashed()
            ->where('mail_account_id', $message->mail_account_id)
            ->where('provider_message_id', $providerMessageId)
            ->whereKeyNot($message->id)
            ->exists();

        $message->forceFill(array_filter([
            'provider_message_id' => $taken ? null : $providerMessageId,
            'folder_id' => $folder->id,
            'imap_uid' => $uid,
        ], fn ($value) => $value !== null))->save();
    }

    /**
     * Re-read flags for recently received messages in the folder, apply changes locally and soft
     * delete messages that no longer exist on the server (deleted or moved away).
     */
    protected function refreshRecentFlags(MailAccount $account, ImapSession $session, MailFolder $folder, SyncResult $result): void
    {
        $since = now()->subDays(self::FLAG_REFRESH_DAYS);

        $local = Message::query()
            ->where('mail_account_id', $account->id)
            ->where('folder_id', $folder->id)
            ->whereNotNull('imap_uid')
            ->where(fn ($query) => $query->where('received_at', '>=', $since)->orWhere('sent_at', '>=', $since))
            ->get();

        if ($local->isEmpty()) {
            return;
        }

        $remote = $session->fetchFlags($folder->provider_id, $local->pluck('imap_uid')->map(fn ($uid) => (int) $uid)->all());
        $touched = [];

        foreach ($local as $message) {
            $uid = (int) $message->imap_uid;

            if (! array_key_exists($uid, $remote)) {
                $message->delete();
                $result->deleted++;
                $touched[] = $message->conversation_id;

                continue;
            }

            $state = $this->mapper->state($folder->type, $remote[$uid]);
            $isRead = $state->isRead || $message->isOutbound();

            if ($message->is_read === $isRead && $message->is_starred === $state->isStarred && $message->mailbox_state == $state->toArray()) {
                continue;
            }

            $message->forceFill([
                'mailbox_state' => $state->toArray(),
                'is_read' => $isRead,
                'is_starred' => $state->isStarred,
            ])->save();

            $result->updated++;
            $touched[] = $message->conversation_id;
        }

        $this->recomputeConversations($touched);
    }

    /**
     * @param  array<int, int>  $conversationIds
     */
    protected function recomputeConversations(array $conversationIds): void
    {
        Conversation::withTrashed()
            ->whereIn('id', array_unique($conversationIds))
            ->get()
            ->each(function (Conversation $conversation) {
                $this->ingestor->recomputeFlags($conversation);
                $conversation->refreshCounters();
            });
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function storeFlags(MailAccount $account, Collection $messages, string $flag, bool $add): void
    {
        $groups = $this->groupByFolder($account, $messages);

        if ($groups->isEmpty()) {
            return;
        }

        $this->withSession($account, function (ImapSession $session) use ($groups, $flag, $add) {
            foreach ($groups as ['folder' => $folder, 'messages' => $grouped]) {
                $session->setFlags($folder->provider_id, $this->uids($grouped), [$flag], $add);
            }
        });
    }

    /**
     * Move messages on the server and relocate the local rows (folder, UID, provider id, state).
     *
     * @param  Collection<int, Message>  $messages
     * @param  Closure(ImapSession): MailFolder  $resolveTarget
     */
    protected function moveMessages(MailAccount $account, Collection $messages, Closure $resolveTarget): void
    {
        $groups = $this->groupByFolder($account, $messages);

        if ($groups->isEmpty()) {
            return;
        }

        $this->withSession($account, function (ImapSession $session) use ($groups, $resolveTarget) {
            $target = $resolveTarget($session);
            $touched = [];

            foreach ($groups as ['folder' => $folder, 'messages' => $grouped]) {
                if ($folder->id === $target->id) {
                    continue;
                }

                $mapping = $session->move($folder->provider_id, $this->uids($grouped), $target->provider_id);
                $validity = $this->uidValidityFor($session, $target);

                foreach ($grouped as $message) {
                    $newUid = $mapping[(int) $message->imap_uid] ?? null;

                    if ($newUid === null && $message->message_id_header) {
                        $found = $session->uidsByMessageId($target->provider_id, $message->message_id_header);
                        $newUid = $found === [] ? null : max($found);
                    }

                    $this->relocate($message, $target, $newUid, $validity);
                    $touched[] = $message->conversation_id;
                }
            }

            $this->recomputeConversations($touched);
        });
    }

    protected function relocate(Message $message, MailFolder $target, ?int $uid, int $uidValidity): void
    {
        $flags = array_values(array_filter([
            $message->is_read ? '\Seen' : null,
            $message->is_starred ? '\Flagged' : null,
        ]));

        $state = $this->mapper->state($target->type, $flags);

        $message->forceFill([
            'folder_id' => $target->id,
            'imap_uid' => $uid,
            'mailbox_state' => $state->toArray(),
            'provider_labels' => [$target->provider_id],
            'is_read' => $state->isRead,
            'is_starred' => $state->isStarred,
        ])->save();

        if ($uid !== null) {
            $this->adoptProviderId($message, sprintf('%s:%d:%d', $target->provider_id, $uidValidity, $uid), $target, $uid);
        }
    }

    protected function uidValidityFor(ImapSession $session, MailFolder $folder): int
    {
        if ($folder->uid_validity) {
            return (int) $folder->uid_validity;
        }

        $status = $session->selectFolder($folder->provider_id);
        $folder->forceFill(['uid_validity' => $status->uidValidity, 'uid_next' => $status->uidNext])->save();

        return $status->uidValidity;
    }

    /**
     * Find the folder of the given type, creating it on the server when the mailbox has none.
     */
    protected function ensureFolder(MailAccount $account, ImapSession $session, FolderType $type, string $defaultName): MailFolder
    {
        if ($existing = $this->folderOfType($account, $type)) {
            return $existing;
        }

        $this->syncFolders($account, $session);

        if ($existing = $this->folderOfType($account, $type)) {
            return $existing;
        }

        $path = $this->folderPathFor($account, $defaultName);

        $session->createFolder($path);
        $this->syncFolders($account, $session);

        $folder = $account->folders()->where('provider_id', $path)->first()
            ?? $account->folders()->where('type', $type)->first();

        if (! $folder) {
            throw new TransientProviderException(sprintf('Created folder "%s" but the server did not list it.', $path));
        }

        if ($folder->type !== $type) {
            $folder->forceFill(['type' => $type])->save();
        }

        return $folder;
    }

    protected function folderOfType(MailAccount $account, FolderType $type): ?MailFolder
    {
        return $account->folders()
            ->where('type', $type)
            ->where('is_selectable', true)
            ->orderByDesc('sync_enabled')
            ->first();
    }

    /**
     * Servers with an INBOX namespace (Courier, some Dovecot setups) want "INBOX.Archive".
     */
    protected function folderPathFor(MailAccount $account, string $name): string
    {
        $prefixed = $account->folders()
            ->where('provider_id', '!=', 'INBOX')
            ->get()
            ->map(fn (MailFolder $folder) => $folder->provider_id)
            ->first(fn (string $path) => preg_match('/^INBOX[.\/]/i', $path) === 1);

        return $prefixed ? substr($prefixed, 0, 6).$name : $name;
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, array{folder: MailFolder, messages: Collection<int, Message>}>
     */
    protected function groupByFolder(MailAccount $account, Collection $messages): Collection
    {
        $usable = $messages->filter(fn (Message $message) => $message->mail_account_id === $account->id && $message->imap_uid !== null && $message->folder_id !== null);

        if ($usable->isEmpty()) {
            return collect();
        }

        $folders = MailFolder::query()->whereIn('id', $usable->pluck('folder_id')->unique())->get()->keyBy('id');
        $groups = collect();

        foreach ($usable->groupBy('folder_id') as $folderId => $group) {
            $folder = $folders->get($folderId);

            if ($folder instanceof MailFolder) {
                $groups->push(['folder' => $folder, 'messages' => $group->values()]);
            }
        }

        return $groups;
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<int, int>
     */
    protected function uids(Collection $messages): array
    {
        return $messages->pluck('imap_uid')->filter()->map(fn ($uid) => (int) $uid)->unique()->values()->all();
    }

    protected function appendToSent(MailAccount $account, string $raw): ?string
    {
        return $this->withSession($account, function (ImapSession $session) use ($account, $raw) {
            $sent = $this->ensureFolder($account, $session, FolderType::Sent, 'Sent');
            $uid = $session->append($sent->provider_id, $raw, ['\Seen']);

            if ($uid === null) {
                return null;
            }

            return sprintf('%s:%d:%d', $sent->provider_id, $this->uidValidityFor($session, $sent), $uid);
        });
    }

    protected function providerMessageId(MailFolder $folder, int $uid): string
    {
        return sprintf('%s:%d:%d', $folder->provider_id, (int) $folder->uid_validity, $uid);
    }

    protected function generateMessageId(string $fromEmail): string
    {
        $domain = Str::after($fromEmail, '@');

        return sprintf('%s@%s', Str::uuid(), $domain !== '' && $domain !== $fromEmail ? $domain : 'tandem.local');
    }

    /**
     * @param  array<string, mixed>  $smtp
     */
    protected function translateSmtp(TransportExceptionInterface $e, array $smtp): ProviderException
    {
        $detail = strtolower($e->getMessage());
        $endpoint = sprintf('%s:%s', $smtp['host'] ?? 'smtp', $smtp['port'] ?? '');

        if (str_contains($detail, '535') || str_contains($detail, '534') || str_contains($detail, 'authentication') || str_contains($detail, 'credentials')) {
            return new AuthenticationRequiredException('The SMTP server rejected the username or password.', 0, $e);
        }

        if (str_contains($detail, 'tls') || str_contains($detail, 'ssl') || str_contains($detail, 'certificate')) {
            return new TransientProviderException(sprintf('Could not establish a secure SMTP connection to %s. Check the encryption setting (SSL/TLS vs STARTTLS).', $endpoint), 0, $e);
        }

        return new TransientProviderException(sprintf('SMTP error at %s: %s', $endpoint, rtrim($e->getMessage(), '.')), 0, $e);
    }

    protected function initialSyncDays(): int
    {
        return max(1, (int) config('tandem.imap.initial_sync_days', 90));
    }

    protected function batchSize(): int
    {
        return max(1, (int) config('tandem.imap.batch_size', 100));
    }
}
