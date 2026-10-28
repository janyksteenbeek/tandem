<?php

namespace App\Enums;

enum MailboxView: string
{
    case Inbox = 'inbox';
    case Starred = 'starred';
    case Snoozed = 'snoozed';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Archive = 'archive';
    case Spam = 'spam';
    case Trash = 'trash';
    case All = 'all';
    case Open = 'open';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case Pending = 'pending';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned to me',
            self::All => 'Everything',
            default => ucfirst($this->value),
        };
    }

    /**
     * Views that make sense for a personal mailbox.
     *
     * @return array<self>
     */
    public static function personal(): array
    {
        return [self::Inbox, self::Starred, self::Snoozed, self::Drafts, self::Sent, self::Archive, self::Spam, self::Trash];
    }

    /**
     * Views that make sense for a shared team inbox.
     *
     * @return array<self>
     */
    public static function shared(): array
    {
        return [self::Open, self::Assigned, self::Unassigned, self::Pending, self::Snoozed, self::Closed, self::Drafts, self::Sent, self::Spam, self::Trash, self::All];
    }
}
