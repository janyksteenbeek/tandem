<?php

namespace App\Enums;

enum MailProvider: string
{
    case Gmail = 'gmail';
    case Imap = 'imap';
    case Forwarding = 'forwarding';
    case GoogleGroup = 'google_group';

    public function label(): string
    {
        return match ($this) {
            self::Gmail => 'Gmail',
            self::Imap => 'IMAP / SMTP',
            self::Forwarding => 'Forward',
            self::GoogleGroup => 'Google Group',
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
            self::Forwarding, self::GoogleGroup => [
                ProviderCapability::Send,
                ProviderCapability::Push,
            ],
        };
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * Channels that receive mail on a generated address of the forwarding domain
     * (Cloudflare Email Routing) instead of connecting to a mailbox.
     */
    public function usesForwardingAddress(): bool
    {
        return in_array($this, self::forwardingChannels(), true);
    }

    /**
     * @return array<int, self>
     */
    public static function forwardingChannels(): array
    {
        return [self::Forwarding, self::GoogleGroup];
    }

    /**
     * @return array<int, string>
     */
    public static function forwardingChannelValues(): array
    {
        return array_map(fn (self $channel) => $channel->value, self::forwardingChannels());
    }

    /**
     * Preferred order when an inbox has several channels and one must send a reply:
     * real mailboxes first, forwarding-based channels last.
     *
     * @return array<int, string>
     */
    public static function sendingPreference(): array
    {
        return [self::Gmail->value, self::Imap->value, self::Forwarding->value, self::GoogleGroup->value];
    }
}
