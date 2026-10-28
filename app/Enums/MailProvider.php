<?php

namespace App\Enums;

enum MailProvider: string
{
    case Gmail = 'gmail';
    case Imap = 'imap';
    case Forwarding = 'forwarding';

    public function label(): string
    {
        return match ($this) {
            self::Gmail => 'Gmail',
            self::Imap => 'IMAP / SMTP',
            self::Forwarding => 'Forwarding address',
        };
    }

    /**
     * @return array<ProviderCapability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Gmail => [
                ProviderCapability::Send,
                ProviderCapability::Push,
                ProviderCapability::Labels,
                ProviderCapability::Threads,
                ProviderCapability::Drafts,
                ProviderCapability::Flags,
                ProviderCapability::Archive,
                ProviderCapability::Trash,
                ProviderCapability::Spam,
            ],
            self::Imap => [
                ProviderCapability::Send,
                ProviderCapability::Idle,
                ProviderCapability::Folders,
                ProviderCapability::Flags,
                ProviderCapability::Archive,
                ProviderCapability::Trash,
                ProviderCapability::Spam,
            ],
            self::Forwarding => [
                ProviderCapability::Send,
                ProviderCapability::Push,
            ],
        };
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }
}
