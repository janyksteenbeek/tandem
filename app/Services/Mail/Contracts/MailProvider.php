<?php

namespace App\Services\Mail\Contracts;

use App\Enums\MailProvider as ProviderType;
use App\Enums\ProviderCapability;
use App\Models\Attachment;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\Message;
use App\Models\SyncRun;
use App\Services\Mail\Data\ConnectionTestResult;
use App\Services\Mail\Data\FolderInfo;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Data\SentMessageResult;
use App\Services\Mail\Data\SyncResult;
use Illuminate\Support\Collection;

/**
 * Every mail adapter (Gmail, IMAP, forwarding) implements this contract so the
 * rest of Tandem never cares where a message came from.
 */
interface MailProvider
{
    public function type(): ProviderType;

    public function supports(ProviderCapability $capability): bool;

    /**
     * Verify credentials and connectivity without mutating anything.
     */
    public function testConnection(MailAccount $account): ConnectionTestResult;

    /**
     * List folders/labels at the provider. Returning an empty collection is fine for providers without folders.
     *
     * @return Collection<int, FolderInfo>
     */
    public function discoverFolders(MailAccount $account): Collection;

    /**
     * Fetch the recent history of the mailbox. Must be resumable: if the job dies, running it again continues.
     */
    public function initialSync(MailAccount $account, SyncRun $run): SyncResult;

    /**
     * Fetch only what changed since the stored cursor (Gmail historyId, IMAP UIDs, ...).
     */
    public function incrementalSync(MailAccount $account, SyncRun $run): SyncResult;

    /**
     * Download attachment bytes that were not stored during sync (lazy attachments).
     */
    public function fetchAttachment(MailAccount $account, Attachment $attachment): string;

    /**
     * Deliver a message. Must be safe to call once per idempotency key; duplicates are prevented upstream.
     */
    public function send(MailAccount $account, OutgoingMessage $message): SentMessageResult;

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function markRead(MailAccount $account, Collection $messages, bool $read): void;

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function setStarred(MailAccount $account, Collection $messages, bool $starred): void;

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function archive(MailAccount $account, Collection $messages): void;

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function moveToInbox(MailAccount $account, Collection $messages): void;

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function trash(MailAccount $account, Collection $messages): void;

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function restore(MailAccount $account, Collection $messages): void;

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function markSpam(MailAccount $account, Collection $messages, bool $spam): void;

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function moveToFolder(MailAccount $account, Collection $messages, MailFolder $folder): void;

    /**
     * Apply or remove a provider label (Gmail) for the given messages. No-op when unsupported.
     *
     * @param  Collection<int, Message>  $messages
     */
    public function setLabel(MailAccount $account, Collection $messages, MailFolder $label, bool $applied): void;

    /**
     * Permanently disconnect: revoke tokens, stop watches, close sessions.
     */
    public function disconnect(MailAccount $account): void;
}
