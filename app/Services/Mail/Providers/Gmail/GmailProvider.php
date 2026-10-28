<?php

namespace App\Services\Mail\Providers\Gmail;

use App\Enums\FolderType;
use App\Enums\MailProvider as ProviderType;
use App\Models\Attachment;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\Message;
use App\Models\SyncRun;
use App\Services\Mail\Data\ConnectionTestResult;
use App\Services\Mail\Data\FolderInfo;
use App\Services\Mail\Data\IncomingMessage;
use App\Services\Mail\Data\MailboxState;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Data\SentMessageResult;
use App\Services\Mail\Data\SyncResult;
use App\Services\Mail\Exceptions\ProviderException;
use App\Services\Mail\Ingestion\MessageIngestor;
use App\Services\Mail\Ingestion\MimeMessageParser;
use App\Services\Mail\Outbound\SymfonyEmailBuilder;
use App\Services\Mail\Providers\AbstractMailProvider;
use Carbon\CarbonImmutable;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\BatchModifyMessagesRequest;
use Google\Service\Gmail\History;
use Google\Service\Gmail\Label;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gmail adapter built on the Gmail REST API. Messages are fetched as raw RFC 5322 so the shared
 * MIME parser and ingestor handle them exactly like mail from any other provider.
 */
class GmailProvider extends AbstractMailProvider
{
    /**
     * Default upper bound of full message fetches per sync run; the job re-dispatches itself for the rest.
     */
    public const int DEFAULT_MESSAGES_PER_RUN = 100;

    /**
     * Gmail allows at most 1000 ids per batchModify call.
     */
    protected const int BATCH_MODIFY_CHUNK = 1000;

    /** @var array<string, array{type: FolderType, name: string}> */
    protected const array SYSTEM_LABELS = [
        'INBOX' => ['type' => FolderType::Inbox, 'name' => 'Inbox'],
        'SENT' => ['type' => FolderType::Sent, 'name' => 'Sent'],
        'DRAFT' => ['type' => FolderType::Drafts, 'name' => 'Drafts'],
        'TRASH' => ['type' => FolderType::Trash, 'name' => 'Trash'],
        'SPAM' => ['type' => FolderType::Spam, 'name' => 'Spam'],
        'STARRED' => ['type' => FolderType::Starred, 'name' => 'Starred'],
        'IMPORTANT' => ['type' => FolderType::Important, 'name' => 'Important'],
    ];

    public function __construct(
        protected GmailClientFactory $clients,
        protected MimeMessageParser $parser,
        protected MessageIngestor $ingestor,
        protected SymfonyEmailBuilder $emails,
        protected GmailWatchManager $watches,
    ) {}

    public function type(): ProviderType
    {
        return ProviderType::Gmail;
    }

    public function testConnection(MailAccount $account): ConnectionTestResult
    {
        try {
            $profile = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users->getProfile('me'));
        } catch (ProviderException $e) {
            return ConnectionTestResult::failure($e->getMessage());
        }

        return ConnectionTestResult::success(details: [
            'email' => $profile->getEmailAddress(),
            'history_id' => (string) $profile->getHistoryId(),
            'messages_total' => (int) $profile->getMessagesTotal(),
        ]);
    }

    /**
     * @return Collection<int, FolderInfo>
     */
    public function discoverFolders(MailAccount $account): Collection
    {
        $response = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_labels->listUsersLabels('me'));

        $folders = collect();

        foreach ($response->getLabels() ?: [] as $label) {
            $folders->push($this->folderInfo($label));
        }

        return $folders;
    }

    /**
     * Upsert MailFolder rows for every Gmail label so the UI can offer them as labels.
     *
     * @return Collection<int, MailFolder>
     */
    public function syncFolders(MailAccount $account): Collection
    {
        return $this->discoverFolders($account)->map(fn (FolderInfo $info) => $account->folders()->updateOrCreate(
            ['provider_id' => $info->providerId],
            ['name' => $info->name, 'type' => $info->type, 'is_selectable' => $info->isSelectable],
        ));
    }

    public function initialSync(MailAccount $account, SyncRun $run): SyncResult
    {
        $result = new SyncResult;
        $pageToken = $account->syncState('initial_page_token');

        if (! $account->syncState('history_id')) {
            $profile = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users->getProfile('me'));
            $account->mergeSyncState(['history_id' => (string) $profile->getHistoryId()]);
        }

        if ($pageToken === null) {
            $this->syncFolders($account);
        }

        $query = sprintf('newer_than:%dd -in:chats', max(1, (int) config('tandem.gmail.initial_sync_days', 90)));
        $limit = $this->messagesPerRun();
        $processed = 0;

        do {
            $params = ['q' => $query, 'includeSpamTrash' => true, 'maxResults' => $limit - $processed];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $page = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_messages->listUsersMessages('me', $params));

            foreach ($page->getMessages() ?: [] as $stub) {
                $this->fetchAndIngest($account, (string) $stub->getId(), $result);
                $processed++;
            }

            $pageToken = $page->getNextPageToken() ?: null;
            $account->mergeSyncState(['initial_page_token' => $pageToken]);
        } while ($pageToken !== null && $processed < $limit);

        if ($pageToken !== null) {
            $result->hasMore = true;

            return $result;
        }

        $account->forceFill(['initial_sync_completed_at' => $account->initial_sync_completed_at ?? now()])->save();
        $account->mergeSyncState(['initial_page_token' => null]);

        return $result;
    }

    public function incrementalSync(MailAccount $account, SyncRun $run): SyncResult
    {
        $result = new SyncResult;

        if ($account->syncState('reconcile_page_token') !== null) {
            return $this->continueReconcile($account, $result);
        }

        $startHistoryId = $account->syncState('history_id');

        if (! $startHistoryId) {
            return $this->startReconcile($account, $result, 'No history cursor stored for the account.');
        }

        $pageToken = null;
        $newestHistoryId = (string) $startHistoryId;

        try {
            do {
                $params = [
                    'startHistoryId' => (string) $startHistoryId,
                    'historyTypes' => ['messageAdded', 'messageDeleted', 'labelAdded', 'labelRemoved'],
                    'maxResults' => 500,
                ];

                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $page = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_history->listUsersHistory('me', $params));

                $this->applyHistory($account, $page->getHistory() ?: [], $result);

                $pageToken = $page->getNextPageToken() ?: null;
                $newestHistoryId = $page->getHistoryId() ? (string) $page->getHistoryId() : $newestHistoryId;
            } while ($pageToken !== null);
        } catch (GoogleServiceException $e) {
            if ((int) $e->getCode() === 404) {
                return $this->startReconcile($account, $result, sprintf('History id %s is no longer available at Gmail.', $startHistoryId));
            }

            throw $this->clients->translate($e);
        }

        $account->mergeSyncState(['history_id' => $newestHistoryId]);

        return $result;
    }

    public function fetchAttachment(MailAccount $account, Attachment $attachment): string
    {
        $messageId = $attachment->message->provider_message_id;

        if (! $messageId || ! $attachment->provider_attachment_id) {
            throw new ProviderException('The attachment has no Gmail identifiers to fetch it with.');
        }

        $body = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_messages_attachments->get('me', $messageId, $attachment->provider_attachment_id));

        return $this->decodeBase64Url((string) $body->getData());
    }

    public function send(MailAccount $account, OutgoingMessage $message): SentMessageResult
    {
        $email = $this->emails->build($message);
        $messageId = trim((string) ($message->messageId ?? $this->generateMessageId($message)), '<>');

        if ($message->messageId === null) {
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        }

        $payload = new GmailMessage;
        $payload->setRaw($this->encodeBase64Url($email->toString()));

        if ($message->providerThreadId) {
            $payload->setThreadId($message->providerThreadId);
        }

        $sent = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_messages->send('me', $payload));

        return new SentMessageResult($messageId, $sent->getId() ? (string) $sent->getId() : null, $sent->getThreadId() ? (string) $sent->getThreadId() : null);
    }

    public function markRead(MailAccount $account, Collection $messages, bool $read): void
    {
        $this->modifyLabels($account, $messages, add: $read ? [] : ['UNREAD'], remove: $read ? ['UNREAD'] : []);
    }

    public function setStarred(MailAccount $account, Collection $messages, bool $starred): void
    {
        $this->modifyLabels($account, $messages, add: $starred ? ['STARRED'] : [], remove: $starred ? [] : ['STARRED']);
    }

    public function archive(MailAccount $account, Collection $messages): void
    {
        $this->modifyLabels($account, $messages, add: [], remove: ['INBOX']);
    }

    public function moveToInbox(MailAccount $account, Collection $messages): void
    {
        $this->modifyLabels($account, $messages, add: ['INBOX'], remove: ['TRASH', 'SPAM']);
    }

    public function trash(MailAccount $account, Collection $messages): void
    {
        foreach ($this->providerIds($messages) as $id) {
            $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_messages->trash('me', $id));
        }
    }

    public function restore(MailAccount $account, Collection $messages): void
    {
        foreach ($this->providerIds($messages) as $id) {
            $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_messages->untrash('me', $id));
        }
    }

    public function markSpam(MailAccount $account, Collection $messages, bool $spam): void
    {
        $this->modifyLabels($account, $messages, add: $spam ? ['SPAM'] : ['INBOX'], remove: $spam ? ['INBOX'] : ['SPAM']);
    }

    public function moveToFolder(MailAccount $account, Collection $messages, MailFolder $folder): void
    {
        match ($folder->provider_id) {
            'INBOX' => $this->moveToInbox($account, $messages),
            'TRASH' => $this->trash($account, $messages),
            'SPAM' => $this->markSpam($account, $messages, true),
            default => $this->modifyLabels($account, $messages, add: [$folder->provider_id], remove: ['INBOX']),
        };
    }

    public function setLabel(MailAccount $account, Collection $messages, MailFolder $label, bool $applied): void
    {
        $this->modifyLabels($account, $messages, add: $applied ? [$label->provider_id] : [], remove: $applied ? [] : [$label->provider_id]);
    }

    public function disconnect(MailAccount $account): void
    {
        try {
            $this->watches->stop($account);
        } catch (Throwable $e) {
            Log::info('Could not stop Gmail watch during disconnect', ['account_id' => $account->id, 'error' => $e->getMessage()]);
        }

        try {
            $token = $account->credential('refresh_token') ?? $account->credential('access_token');

            if ($token) {
                $this->clients->oauthClient($this->clients->oauthOrganizationFor($account))->revokeToken($token);
            }
        } catch (Throwable $e) {
            Log::info('Could not revoke Gmail token during disconnect', ['account_id' => $account->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Apply one page of history records. Within a page, additions are applied first, then label
     * changes (deduplicated per message), then deletions, mirroring Gmail's own ordering guidance.
     *
     * @param  array<int, History>  $records
     */
    protected function applyHistory(MailAccount $account, array $records, SyncResult $result): void
    {
        $added = [];
        $relabeled = [];
        $deleted = [];

        foreach ($records as $record) {
            foreach ($record->getMessagesAdded() ?: [] as $entry) {
                $added[(string) $entry->getMessage()->getId()] = true;
            }

            foreach ([...($record->getLabelsAdded() ?: []), ...($record->getLabelsRemoved() ?: [])] as $entry) {
                $relabeled[(string) $entry->getMessage()->getId()] = true;
            }

            foreach ($record->getMessagesDeleted() ?: [] as $entry) {
                $deleted[(string) $entry->getMessage()->getId()] = true;
            }
        }

        foreach (array_keys($added) as $id) {
            if (isset($deleted[$id])) {
                continue;
            }

            $this->fetchAndIngest($account, $id, $result);
        }

        foreach (array_keys($relabeled) as $id) {
            if (isset($deleted[$id]) || isset($added[$id])) {
                continue;
            }

            $this->refreshLabels($account, $id, $result);
        }

        foreach (array_keys($deleted) as $id) {
            $this->deleteLocally($account, $id, $result);
        }
    }

    /**
     * Download the raw MIME for one Gmail message and run it through the ingestor.
     */
    protected function fetchAndIngest(MailAccount $account, string $id, SyncResult $result): void
    {
        try {
            $message = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_messages->get('me', $id, ['format' => 'raw']));
        } catch (GoogleServiceException $e) {
            if ((int) $e->getCode() === 404) {
                $result->skipped++;

                return;
            }

            throw $this->clients->translate($e);
        }

        $labels = array_values(array_map('strval', $message->getLabelIds() ?: []));

        if (in_array('DRAFT', $labels, true)) {
            $result->skipped++;

            return;
        }

        $result->fetched++;

        try {
            $incoming = $this->parser->parse($this->decodeBase64Url((string) $message->getRaw()), [
                'providerMessageId' => (string) $message->getId(),
                'providerThreadId' => $message->getThreadId() ? (string) $message->getThreadId() : null,
                'labels' => $labels,
                'state' => MailboxState::fromGmailLabels($labels),
                'receivedAt' => $this->internalDate($message),
                'sizeBytes' => $message->getSizeEstimate() ? (int) $message->getSizeEstimate() : null,
            ]);

            $ingested = $this->ingestor->ingest($account, $incoming);
        } catch (Throwable $e) {
            Log::error('Failed to ingest Gmail message', ['account_id' => $account->id, 'gmail_id' => $id, 'error' => $e->getMessage()]);
            $result->errors[] = sprintf('%s: %s', $id, $e->getMessage());
            $result->skipped++;

            return;
        }

        if ($ingested->created) {
            $result->created++;
        } else {
            $result->updated++;
        }
    }

    /**
     * Re-read the label set of a message we already hold and let the ingestor refresh its state.
     */
    protected function refreshLabels(MailAccount $account, string $id, SyncResult $result): void
    {
        $local = Message::withTrashed()->where('mail_account_id', $account->id)->where('provider_message_id', $id)->first();

        if (! $local) {
            $this->fetchAndIngest($account, $id, $result);

            return;
        }

        try {
            $message = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_messages->get('me', $id, ['format' => 'minimal']));
        } catch (GoogleServiceException $e) {
            if ((int) $e->getCode() === 404) {
                $this->deleteLocally($account, $id, $result);

                return;
            }

            throw $this->clients->translate($e);
        }

        $labels = array_values(array_map('strval', $message->getLabelIds() ?: []));

        $this->ingestor->ingest($account, new IncomingMessage(
            messageId: $local->message_id_header,
            from: null,
            to: [],
            subject: $local->subject,
            sentAt: $local->sent_at ?? now(),
            labels: $labels,
            state: MailboxState::fromGmailLabels($labels),
            providerMessageId: $id,
            providerThreadId: $message->getThreadId() ? (string) $message->getThreadId() : $local->provider_thread_id,
        ));

        $result->updated++;
    }

    protected function deleteLocally(MailAccount $account, string $id, SyncResult $result): void
    {
        $local = Message::query()->where('mail_account_id', $account->id)->where('provider_message_id', $id)->first();

        if (! $local) {
            return;
        }

        $local->delete();
        $result->deleted++;

        $conversation = $local->conversation()->withTrashed()->first();

        if ($conversation) {
            $this->ingestor->recomputeFlags($conversation);
            $conversation->refreshCounters();
        }
    }

    /**
     * Begin a reconcile: remember the current mailbox history id, then page through recent mail.
     * The cursor only moves to that id once every page has been ingested so nothing slips through.
     */
    protected function startReconcile(MailAccount $account, SyncResult $result, string $reason): SyncResult
    {
        Log::info('Gmail reconcile started', ['account_id' => $account->id, 'reason' => $reason]);

        $profile = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users->getProfile('me'));

        $since = $account->last_synced_at
            ? sprintf('after:%d', $account->last_synced_at->copy()->subDay()->getTimestamp())
            : 'newer_than:7d';

        $account->mergeSyncState([
            'reconcile_history_id' => (string) $profile->getHistoryId(),
            'reconcile_query' => $since.' -in:chats',
            'reconcile_page_token' => '',
        ]);

        return $this->continueReconcile($account, $result);
    }

    protected function continueReconcile(MailAccount $account, SyncResult $result): SyncResult
    {
        $pageToken = (string) $account->syncState('reconcile_page_token', '');
        $query = (string) $account->syncState('reconcile_query', 'newer_than:7d -in:chats');
        $limit = $this->messagesPerRun();
        $processed = 0;

        do {
            $params = ['q' => $query, 'includeSpamTrash' => true, 'maxResults' => $limit - $processed];

            if ($pageToken !== '') {
                $params['pageToken'] = $pageToken;
            }

            $page = $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_messages->listUsersMessages('me', $params));

            foreach ($page->getMessages() ?: [] as $stub) {
                $this->fetchAndIngest($account, (string) $stub->getId(), $result);
                $processed++;
            }

            $pageToken = (string) ($page->getNextPageToken() ?: '');
            $account->mergeSyncState(['reconcile_page_token' => $pageToken === '' ? null : $pageToken]);
        } while ($pageToken !== '' && $processed < $limit);

        if ($pageToken !== '') {
            $result->hasMore = true;

            return $result;
        }

        $account->mergeSyncState([
            'history_id' => (string) $account->syncState('reconcile_history_id'),
            'reconcile_history_id' => null,
            'reconcile_query' => null,
            'reconcile_page_token' => null,
        ]);

        return $result;
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @param  array<int, string>  $add
     * @param  array<int, string>  $remove
     */
    protected function modifyLabels(MailAccount $account, Collection $messages, array $add, array $remove): void
    {
        $ids = $this->providerIds($messages);

        if ($ids === [] || ($add === [] && $remove === [])) {
            return;
        }

        foreach (array_chunk($ids, self::BATCH_MODIFY_CHUNK) as $chunk) {
            $request = new BatchModifyMessagesRequest;
            $request->setIds($chunk);

            if ($add !== []) {
                $request->setAddLabelIds(array_values(array_unique($add)));
            }

            if ($remove !== []) {
                $request->setRemoveLabelIds(array_values(array_unique($remove)));
            }

            $this->clients->call($account, fn (Gmail $gmail) => $gmail->users_messages->batchModify('me', $request));
        }
    }

    protected function messagesPerRun(): int
    {
        return max(1, (int) config('tandem.gmail.sync_batch_size', self::DEFAULT_MESSAGES_PER_RUN));
    }

    protected function folderInfo(Label $label): FolderInfo
    {
        $id = (string) $label->getId();
        $system = self::SYSTEM_LABELS[$id] ?? null;

        if ($system) {
            return new FolderInfo($id, $system['name'], $system['type'], true, ['label_type' => 'system']);
        }

        $selectable = $label->getType() !== 'system' || ! (Str::startsWith($id, 'CATEGORY_') || in_array($id, ['CHAT', 'UNREAD'], true));

        return new FolderInfo(
            $id,
            (string) $label->getName(),
            FolderType::Custom,
            $selectable,
            ['label_type' => (string) $label->getType(), 'visibility' => $label->getLabelListVisibility()],
        );
    }

    protected function internalDate(GmailMessage $message): ?CarbonImmutable
    {
        $internal = $message->getInternalDate();

        return $internal ? CarbonImmutable::createFromTimestampMs((int) $internal)->utc() : null;
    }

    protected function generateMessageId(OutgoingMessage $message): string
    {
        $domain = Str::after($message->from->email, '@') ?: 'tandem.local';

        return sprintf('%s@%s', Str::uuid(), $domain);
    }

    protected function decodeBase64Url(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw new ProviderException('Gmail returned a payload that is not valid base64url.');
        }

        return $decoded;
    }

    protected function encodeBase64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
