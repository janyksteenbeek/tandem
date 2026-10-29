<?php

namespace App\Jobs\Mail;

use App\Enums\MailAccountStatus;
use App\Enums\ProviderCapability;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\Message;
use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use App\Services\Mail\Exceptions\RateLimitedException;
use App\Services\Mail\Exceptions\UnsupportedOperationException;
use App\Services\Mail\MailProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors a local mailbox change (read, star, archive, trash, spam, label) back to the provider.
 * Local state is already updated optimistically; this job only reconciles the remote side.
 */
class PushMailboxChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /**
     * @param  array<int, int>  $messageIds
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public MailAccount $account,
        public array $messageIds,
        public string $operation,
        public array $params = [],
    ) {
        $this->onQueue('mail-sync');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 300];
    }

    public function handle(MailProviderManager $providers): void
    {
        if ($this->account->isPaused() || in_array($this->account->status, [MailAccountStatus::Disconnected, MailAccountStatus::NeedsReauth], true)) {
            return;
        }

        $messages = Message::query()->whereKey($this->messageIds)->whereNotNull('provider_message_id')->get();

        if ($messages->isEmpty()) {
            return;
        }

        $provider = $providers->for($this->account);

        $requirement = match ($this->operation) {
            'read', 'unread', 'star', 'unstar' => ProviderCapability::Flags,
            'archive', 'inbox' => ProviderCapability::Archive,
            'trash', 'restore' => ProviderCapability::Trash,
            'spam', 'not_spam' => ProviderCapability::Spam,
            'move' => ProviderCapability::Folders,
            'label', 'unlabel' => ProviderCapability::Labels,
            default => null,
        };

        if ($requirement === null || ! $provider->supports($requirement)) {
            return;
        }

        try {
            match ($this->operation) {
                'read' => $provider->markRead($this->account, $messages, true),
                'unread' => $provider->markRead($this->account, $messages, false),
                'star' => $provider->setStarred($this->account, $messages, true),
                'unstar' => $provider->setStarred($this->account, $messages, false),
                'archive' => $provider->archive($this->account, $messages),
                'inbox' => $provider->moveToInbox($this->account, $messages),
                'trash' => $provider->trash($this->account, $messages),
                'restore' => $provider->restore($this->account, $messages),
                'spam' => $provider->markSpam($this->account, $messages, true),
                'not_spam' => $provider->markSpam($this->account, $messages, false),
                'move' => $provider->moveToFolder($this->account, $messages, MailFolder::query()->findOrFail((int) $this->params['folder_id'])),
                'label' => $provider->setLabel($this->account, $messages, MailFolder::query()->findOrFail((int) $this->params['folder_id']), true),
                'unlabel' => $provider->setLabel($this->account, $messages, MailFolder::query()->findOrFail((int) $this->params['folder_id']), false),
            };
        } catch (UnsupportedOperationException) {
            return;
        } catch (AuthenticationRequiredException $e) {
            $this->account->markSyncFailed($e->getMessage(), needsReauth: true);
            $this->fail($e);
        } catch (RateLimitedException $e) {
            $this->release($e->retryAfterSeconds);
        } catch (\Throwable $e) {
            Log::warning('Mailbox write-back failed', ['account' => $this->account->id, 'operation' => $this->operation, 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
