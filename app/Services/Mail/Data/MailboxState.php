<?php

namespace App\Services\Mail\Data;

/**
 * Provider-neutral placement of a message inside its mailbox.
 */
final readonly class MailboxState
{
    public function __construct(
        public bool $inInbox = true,
        public bool $isArchived = false,
        public bool $isTrashed = false,
        public bool $isSpam = false,
        public bool $isStarred = false,
        public bool $isRead = false,
        public bool $isDraft = false,
        public bool $isSent = false,
    ) {}

    public static function inbox(bool $read = false): self
    {
        return new self(inInbox: true, isRead: $read);
    }

    /**
     * Derive state from Gmail label IDs.
     *
     * @param  array<int, string>  $labels
     */
    public static function fromGmailLabels(array $labels): self
    {
        $has = fn (string $label) => in_array($label, $labels, true);

        $trashed = $has('TRASH');
        $spam = $has('SPAM');
        $inInbox = $has('INBOX') && ! $trashed && ! $spam;

        return new self(
            inInbox: $inInbox,
            isArchived: ! $inInbox && ! $trashed && ! $spam && ! $has('DRAFT'),
            isTrashed: $trashed,
            isSpam: $spam,
            isStarred: $has('STARRED'),
            isRead: ! $has('UNREAD'),
            isDraft: $has('DRAFT'),
            isSent: $has('SENT'),
        );
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
