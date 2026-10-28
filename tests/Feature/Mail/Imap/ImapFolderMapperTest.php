<?php

use App\Enums\FolderType;
use App\Services\Mail\Providers\Imap\Data\RemoteFolder;
use App\Services\Mail\Providers\Imap\ImapFolderMapper;

beforeEach(function () {
    $this->mapper = new ImapFolderMapper;
});

test('special-use attributes win over folder names', function (array $flags, FolderType $expected) {
    expect($this->mapper->type(new RemoteFolder('Some/Odd Name', '/', $flags)))->toBe($expected);
})->with([
    'sent' => [['\HasNoChildren', '\Sent'], FolderType::Sent],
    'drafts' => [['\Drafts'], FolderType::Drafts],
    'trash' => [['\Trash'], FolderType::Trash],
    'junk' => [['\Junk'], FolderType::Spam],
    'archive' => [['\Archive'], FolderType::Archive],
    'flagged' => [['\Flagged'], FolderType::Starred],
    'all' => [['\All'], FolderType::All],
]);

test('common folder names map to types', function (string $path, FolderType $expected) {
    expect($this->mapper->type(new RemoteFolder($path, '/')))->toBe($expected);
})->with([
    ['INBOX', FolderType::Inbox],
    ['inbox', FolderType::Inbox],
    ['Sent', FolderType::Sent],
    ['Sent Items', FolderType::Sent],
    ['[Gmail]/Sent Mail', FolderType::Sent],
    ['Drafts', FolderType::Drafts],
    ['Trash', FolderType::Trash],
    ['Deleted Items', FolderType::Trash],
    ['Deleted Messages', FolderType::Trash],
    ['Junk', FolderType::Spam],
    ['Junk E-mail', FolderType::Spam],
    ['INBOX/Spam', FolderType::Spam],
    ['Archive', FolderType::Archive],
    ['Archives', FolderType::Archive],
    ['[Gmail]/All Mail', FolderType::All],
    ['Projects/Tandem', FolderType::Custom],
]);

test('noselect folders are not selectable and all-mail folders are not synced', function () {
    $noselect = $this->mapper->toFolderInfo(new RemoteFolder('[Gmail]', '/', ['\Noselect', '\HasChildren']));
    $all = $this->mapper->toFolderInfo(new RemoteFolder('[Gmail]/All Mail', '/', ['\All', '\HasNoChildren']));
    $inbox = $this->mapper->toFolderInfo(new RemoteFolder('INBOX', '/', ['\HasNoChildren']));

    expect($noselect->isSelectable)->toBeFalse()
        ->and($noselect->attributes['sync_enabled'])->toBeFalse()
        ->and($all->type)->toBe(FolderType::All)
        ->and($all->isSelectable)->toBeTrue()
        ->and($all->attributes['sync_enabled'])->toBeFalse()
        ->and($inbox->attributes['sync_enabled'])->toBeTrue()
        ->and($inbox->name)->toBe('INBOX');
});

test('folder type and flags become a mailbox state', function () {
    $inbox = $this->mapper->state(FolderType::Inbox, ['\Seen', '\Flagged']);
    $sent = $this->mapper->state(FolderType::Sent, []);
    $trash = $this->mapper->state(FolderType::Trash, []);
    $spam = $this->mapper->state(FolderType::Spam, []);
    $custom = $this->mapper->state(FolderType::Custom, ['\Draft']);

    expect($inbox->inInbox)->toBeTrue()->and($inbox->isRead)->toBeTrue()->and($inbox->isStarred)->toBeTrue()
        ->and($sent->isSent)->toBeTrue()->and($sent->inInbox)->toBeFalse()->and($sent->isRead)->toBeTrue()
        ->and($trash->isTrashed)->toBeTrue()->and($trash->inInbox)->toBeFalse()
        ->and($spam->isSpam)->toBeTrue()
        ->and($custom->isArchived)->toBeTrue()->and($custom->inInbox)->toBeFalse()->and($custom->isDraft)->toBeTrue();
});
