<?php

namespace App\Services\Mail\Providers;

use App\Enums\Mail\ProviderCapability;
use App\Models\Mail\Attachment;
use App\Models\Mail\MailAccount;
use App\Models\Mail\MailFolder;
use App\Models\Mail\Message;
use App\Services\Mail\Contracts\MailProvider;
use App\Services\Mail\Data\FolderInfo;
use App\Services\Mail\Exceptions\UnsupportedOperationException;
use Illuminate\Support\Collection;

abstract class AbstractMailProvider implements MailProvider
{
    public function supports(ProviderCapability $capability): bool
    {
        return $this->type()->supports($capability);
    }

    /**
     * @return Collection<int, FolderInfo>
     */
    public function discoverFolders(MailAccount $account): Collection
    {
        return collect();
    }

    public function fetchAttachment(MailAccount $account, Attachment $attachment): string
    {
        throw new UnsupportedOperationException(sprintf('%s cannot fetch attachments lazily.', $this->type()->label()));
    }

    public function markRead(MailAccount $account, Collection $messages, bool $read): void
    {
        $this->unsupported(__FUNCTION__);
    }

    public function setStarred(MailAccount $account, Collection $messages, bool $starred): void
    {
        $this->unsupported(__FUNCTION__);
    }

    public function archive(MailAccount $account, Collection $messages): void
    {
        $this->unsupported(__FUNCTION__);
    }

    public function moveToInbox(MailAccount $account, Collection $messages): void
    {
        $this->unsupported(__FUNCTION__);
    }

    public function trash(MailAccount $account, Collection $messages): void
    {
        $this->unsupported(__FUNCTION__);
    }

    public function restore(MailAccount $account, Collection $messages): void
    {
        $this->unsupported(__FUNCTION__);
    }

    public function markSpam(MailAccount $account, Collection $messages, bool $spam): void
    {
        $this->unsupported(__FUNCTION__);
    }

    public function moveToFolder(MailAccount $account, Collection $messages, MailFolder $folder): void
    {
        $this->unsupported(__FUNCTION__);
    }

    public function setLabel(MailAccount $account, Collection $messages, MailFolder $label, bool $applied): void
    {
        $this->unsupported(__FUNCTION__);
    }

    public function disconnect(MailAccount $account): void
    {
        //
    }

    protected function unsupported(string $operation): never
    {
        throw new UnsupportedOperationException(sprintf('%s does not support %s.', $this->type()->label(), $operation));
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<int, string>
     */
    protected function providerIds(Collection $messages): array
    {
        return $messages->pluck('provider_message_id')->filter()->values()->all();
    }
}
