<?php

namespace App\Services\Mail\Providers\Imap;

use App\Enums\FolderType;
use App\Services\Mail\Data\FolderInfo;
use App\Services\Mail\Data\MailboxState;
use App\Services\Mail\Providers\Imap\Data\RemoteFolder;

/**
 * Maps IMAP folders (RFC 6154 special-use attributes and conventional names) to Tandem folder
 * types, and IMAP flags + folder type to the provider-neutral MailboxState.
 */
class ImapFolderMapper
{
    /** @var array<string, FolderType> */
    protected const array SPECIAL_USE = [
        'sent' => FolderType::Sent,
        'drafts' => FolderType::Drafts,
        'trash' => FolderType::Trash,
        'junk' => FolderType::Spam,
        'archive' => FolderType::Archive,
        'flagged' => FolderType::Starred,
        'all' => FolderType::All,
        'important' => FolderType::Important,
    ];

    /** @var array<string, FolderType> */
    protected const array NAMES = [
        'inbox' => FolderType::Inbox,
        'sent' => FolderType::Sent,
        'sent items' => FolderType::Sent,
        'sent mail' => FolderType::Sent,
        'sent messages' => FolderType::Sent,
        'drafts' => FolderType::Drafts,
        'draft' => FolderType::Drafts,
        'trash' => FolderType::Trash,
        'deleted' => FolderType::Trash,
        'deleted items' => FolderType::Trash,
        'deleted messages' => FolderType::Trash,
        'bin' => FolderType::Trash,
        'junk' => FolderType::Spam,
        'junk e-mail' => FolderType::Spam,
        'junk email' => FolderType::Spam,
        'spam' => FolderType::Spam,
        'bulk mail' => FolderType::Spam,
        'archive' => FolderType::Archive,
        'archives' => FolderType::Archive,
        'all mail' => FolderType::All,
        'starred' => FolderType::Starred,
        'important' => FolderType::Important,
    ];

    public function toFolderInfo(RemoteFolder $folder): FolderInfo
    {
        $type = $this->type($folder);

        return new FolderInfo(
            providerId: $folder->path,
            name: $folder->name(),
            type: $type,
            isSelectable: $folder->isSelectable(),
            attributes: [
                'flags' => $folder->flags,
                'delimiter' => $folder->delimiter,
                'sync_enabled' => $this->shouldSync($type, $folder),
            ],
        );
    }

    public function type(RemoteFolder $folder): FolderType
    {
        if (strcasecmp($folder->path, 'INBOX') === 0) {
            return FolderType::Inbox;
        }

        foreach (self::SPECIAL_USE as $flag => $type) {
            if ($folder->hasFlag('\\'.$flag)) {
                return $type;
            }
        }

        $name = strtolower(trim($folder->name()));

        return self::NAMES[$name] ?? FolderType::Custom;
    }

    /**
     * Virtual "All Mail" folders duplicate every message and \Noselect folders cannot be opened.
     */
    public function shouldSync(FolderType $type, RemoteFolder $folder): bool
    {
        if (! $folder->isSelectable()) {
            return false;
        }

        return ! in_array($type, [FolderType::All, FolderType::Starred, FolderType::Important], true);
    }

    /**
     * @param  array<int, string>  $flags
     */
    public function state(FolderType $type, array $flags): MailboxState
    {
        $has = fn (string $flag) => collect($flags)->contains(fn (string $f) => strcasecmp(ltrim($f, '\\'), ltrim($flag, '\\')) === 0);

        $read = $has('\Seen');
        $starred = $has('\Flagged');
        $draft = $has('\Draft') || $type === FolderType::Drafts;

        return match ($type) {
            FolderType::Inbox => new MailboxState(inInbox: true, isStarred: $starred, isRead: $read, isDraft: $draft),
            FolderType::Sent => new MailboxState(inInbox: false, isStarred: $starred, isRead: true, isDraft: $draft, isSent: true),
            FolderType::Trash => new MailboxState(inInbox: false, isTrashed: true, isStarred: $starred, isRead: $read, isDraft: $draft),
            FolderType::Spam => new MailboxState(inInbox: false, isSpam: true, isStarred: $starred, isRead: $read, isDraft: $draft),
            FolderType::Drafts => new MailboxState(inInbox: false, isStarred: $starred, isRead: true, isDraft: true),
            default => new MailboxState(inInbox: false, isArchived: true, isStarred: $starred, isRead: $read, isDraft: $draft),
        };
    }
}
