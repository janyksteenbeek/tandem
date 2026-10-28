<?php

use App\Enums\FolderType;
use App\Enums\SyncRunType;
use App\Models\Conversation;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\Message;
use App\Models\SyncRun;
use App\Services\Mail\Exceptions\UnsupportedOperationException;
use App\Services\Mail\Providers\Imap\ImapClientFactory;
use App\Services\Mail\Providers\Imap\ImapProvider;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Mail\FakeImapSession;

function imapFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/mail/{$name}"));
}

beforeEach(function () {
    Storage::fake('local');
    config()->set('tandem.attachments.disk', 'local');
    config()->set('tandem.imap.batch_size', 100);

    $this->account = MailAccount::factory()->create(['email_address' => 'support@acme.test', 'initial_sync_completed_at' => null]);

    $this->session = (new FakeImapSession)
        ->addFolder('INBOX', ['\HasNoChildren'], 100)
        ->addFolder('Sent', ['\HasNoChildren', '\Sent'], 101)
        ->addFolder('Trash', ['\HasNoChildren', '\Trash'], 102)
        ->addFolder('Junk', ['\HasNoChildren', '\Junk'], 103)
        ->addFolder('[Gmail]', ['\Noselect', '\HasChildren'], 104)
        ->addFolder('[Gmail]/All Mail', ['\All', '\HasNoChildren'], 105);

    $this->mock(ImapClientFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($this->session));

    $this->provider = app(ImapProvider::class);
    $this->run = fn (SyncRunType $type) => SyncRun::start($this->account, $type);
});

test('initial sync discovers folders and ingests recent messages with their flags', function () {
    $this->session->addMessage('INBOX', 5, imapFixture('simple.eml'), ['\Seen', '\Flagged']);

    $result = $this->provider->initialSync($this->account, ($this->run)(SyncRunType::Initial));

    $inbox = MailFolder::where('provider_id', 'INBOX')->firstOrFail();
    $message = Message::firstOrFail();

    expect($result->fetched)->toBe(1)
        ->and($result->created)->toBe(1)
        ->and($result->hasMore)->toBeFalse()
        ->and($message->folder_id)->toBe($inbox->id)
        ->and($message->imap_uid)->toBe(5)
        ->and($message->provider_message_id)->toBe('INBOX:100:5')
        ->and($message->is_read)->toBeTrue()
        ->and($message->is_starred)->toBeTrue()
        ->and($message->mailbox_state['inInbox'])->toBeTrue()
        ->and($message->provider_labels)->toBe(['INBOX'])
        ->and($inbox->type)->toBe(FolderType::Inbox)
        ->and($inbox->uid_validity)->toBe(100)
        ->and($inbox->last_synced_uid)->toBe(5)
        ->and($this->account->fresh()->initial_sync_completed_at)->not->toBeNull()
        ->and($this->session->closed)->toBeTrue();

    expect(MailFolder::where('provider_id', 'Sent')->value('type'))->toBe(FolderType::Sent)
        ->and(MailFolder::where('provider_id', 'Junk')->value('type'))->toBe(FolderType::Spam)
        ->and(MailFolder::where('provider_id', '[Gmail]')->first()->is_selectable)->toBeFalse()
        ->and(MailFolder::where('provider_id', '[Gmail]/All Mail')->first()->sync_enabled)->toBeFalse();

    $conversation = Conversation::firstOrFail();
    expect($conversation->is_starred)->toBeTrue()->and($conversation->unread_count)->toBe(0)->and($conversation->in_inbox)->toBeTrue();
});

test('initial sync is batched and resumable', function () {
    config()->set('tandem.imap.batch_size', 1);
    $this->session->addMessage('INBOX', 1, imapFixture('simple.eml'));
    $this->session->addMessage('INBOX', 2, imapFixture('reply.eml'));

    $first = $this->provider->initialSync($this->account, ($this->run)(SyncRunType::Initial));
    $inbox = MailFolder::where('provider_id', 'INBOX')->firstOrFail();

    expect($first->hasMore)->toBeTrue()
        ->and($first->fetched)->toBe(1)
        ->and($inbox->last_synced_uid)->toBe(1)
        ->and($this->account->fresh()->initial_sync_completed_at)->toBeNull();

    $second = $this->provider->initialSync($this->account, ($this->run)(SyncRunType::Initial));

    expect($second->hasMore)->toBeFalse()
        ->and($second->fetched)->toBe(1)
        ->and(Message::count())->toBe(2)
        ->and($inbox->fresh()->last_synced_uid)->toBe(2)
        ->and($this->account->fresh()->initial_sync_completed_at)->not->toBeNull();
});

test('messages outside the initial window are skipped and never fetched later', function () {
    config()->set('tandem.imap.initial_sync_days', 30);
    $this->session->addMessage('INBOX', 1, imapFixture('simple.eml'), [], now()->subDays(60));
    $this->session->addMessage('INBOX', 2, imapFixture('reply.eml'), [], now()->subDay());

    $this->provider->initialSync($this->account, ($this->run)(SyncRunType::Initial));
    $this->provider->incrementalSync($this->account, ($this->run)(SyncRunType::Incremental));

    expect(Message::count())->toBe(1)
        ->and(Message::first()->imap_uid)->toBe(2)
        ->and(MailFolder::where('provider_id', 'INBOX')->value('last_synced_uid'))->toBe(2);
});

test('incremental sync only fetches new uids and refreshes flags of recent messages', function () {
    $this->session->addMessage('INBOX', 5, imapFixture('simple.eml'));
    $this->provider->initialSync($this->account, ($this->run)(SyncRunType::Initial));

    $conversation = Conversation::firstOrFail();
    expect($conversation->unread_count)->toBe(1);

    $this->session->replaceFlags('INBOX', 5, ['\Seen']);
    $this->session->addMessage('INBOX', 7, imapFixture('reply.eml'));
    $this->session->calls = [];

    $result = $this->provider->incrementalSync($this->account, ($this->run)(SyncRunType::Incremental));

    $fetched = collect($this->session->calls)->where('0', 'fetchMessages')->pluck('1.1')->flatten()->all();

    expect($fetched)->toBe([7])
        ->and($result->created)->toBe(1)
        ->and(Message::count())->toBe(2)
        ->and(Message::where('imap_uid', 5)->first()->is_read)->toBeTrue()
        ->and($conversation->fresh()->unread_count)->toBe(0)
        ->and($conversation->fresh()->message_count)->toBe(2)
        ->and(MailFolder::where('provider_id', 'INBOX')->value('last_synced_uid'))->toBe(7);
});

test('messages removed on the server are soft deleted locally', function () {
    $this->session->addMessage('INBOX', 5, imapFixture('simple.eml'));
    $this->provider->initialSync($this->account, ($this->run)(SyncRunType::Initial));

    $this->session->removeMessage('INBOX', 5);
    $result = $this->provider->incrementalSync($this->account, ($this->run)(SyncRunType::Incremental));

    expect($result->deleted)->toBe(1)
        ->and(Message::count())->toBe(0)
        ->and(Message::withTrashed()->count())->toBe(1)
        ->and(Conversation::first()->message_count)->toBe(0);
});

test('a uidvalidity change resets the folder cursor and re-syncs without duplicates', function () {
    $this->session->addMessage('INBOX', 5, imapFixture('simple.eml'));
    $this->provider->initialSync($this->account, ($this->run)(SyncRunType::Initial));

    $this->session->resetUidValidity('INBOX', 200);
    $result = $this->provider->incrementalSync($this->account, ($this->run)(SyncRunType::Incremental));

    $inbox = MailFolder::where('provider_id', 'INBOX')->firstOrFail();
    $message = Message::firstOrFail();

    expect(Message::count())->toBe(1)
        ->and($result->updated)->toBe(1)
        ->and($result->deleted)->toBe(0)
        ->and($inbox->uid_validity)->toBe(200)
        ->and($inbox->last_synced_uid)->toBe(1)
        ->and($message->imap_uid)->toBe(1)
        ->and($message->provider_message_id)->toBe('INBOX:200:1');
});

test('archiving moves the message on the server and relocates the local row', function () {
    $this->session->addMessage('INBOX', 5, imapFixture('simple.eml'));
    $this->provider->initialSync($this->account, ($this->run)(SyncRunType::Initial));
    $message = Message::firstOrFail();

    $this->provider->archive($this->account, collect([$message]));

    $archive = MailFolder::where('type', FolderType::Archive)->firstOrFail();
    $message->refresh();

    expect($this->session->calls('createFolder'))->toBe(1)
        ->and($this->session->folders)->toHaveKey('Archive')
        ->and($this->session->folders['INBOX']['messages'])->toBeEmpty()
        ->and($message->folder_id)->toBe($archive->id)
        ->and($message->imap_uid)->toBe(1)
        ->and($message->provider_message_id)->toBe('Archive:'.$archive->uid_validity.':1')
        ->and($message->mailbox_state['isArchived'])->toBeTrue()
        ->and($message->mailbox_state['inInbox'])->toBeFalse()
        ->and($message->conversation->is_archived)->toBeTrue();
});

test('trashing moves to the trash folder and restoring moves back to the inbox', function () {
    $this->session->addMessage('INBOX', 5, imapFixture('simple.eml'));
    $this->provider->initialSync($this->account, ($this->run)(SyncRunType::Initial));
    $message = Message::firstOrFail();
    $inbox = MailFolder::where('provider_id', 'INBOX')->firstOrFail();
    $trash = MailFolder::where('provider_id', 'Trash')->firstOrFail();

    $this->provider->trash($this->account, collect([$message]));
    $message->refresh();

    expect($message->folder_id)->toBe($trash->id)
        ->and($message->mailbox_state['isTrashed'])->toBeTrue()
        ->and($message->conversation->is_trashed)->toBeTrue()
        ->and($this->session->folders['Trash']['messages'])->toHaveCount(1);

    $this->provider->restore($this->account, collect([$message->fresh()]));
    $message->refresh();

    expect($message->folder_id)->toBe($inbox->id)
        ->and($message->imap_uid)->toBe(6)
        ->and($message->mailbox_state['inInbox'])->toBeTrue()
        ->and($message->conversation->is_trashed)->toBeFalse()
        ->and($message->conversation->in_inbox)->toBeTrue();
});

test('read and starred flags are written back to the server', function () {
    $this->session->addMessage('INBOX', 5, imapFixture('simple.eml'));
    $this->provider->initialSync($this->account, ($this->run)(SyncRunType::Initial));
    $message = Message::firstOrFail();

    $this->provider->markRead($this->account, collect([$message]), true);
    $this->provider->setStarred($this->account, collect([$message]), true);

    expect($this->session->folders['INBOX']['messages'][5]['flags'])->toContain('\Seen', '\Flagged');

    $this->provider->markRead($this->account, collect([$message]), false);

    expect($this->session->folders['INBOX']['messages'][5]['flags'])->not->toContain('\Seen');
});

test('setting labels is unsupported on imap', function () {
    $folder = MailFolder::create(['mail_account_id' => $this->account->id, 'provider_id' => 'X', 'name' => 'X', 'type' => FolderType::Custom]);

    $this->provider->setLabel($this->account, collect(), $folder, true);
})->throws(UnsupportedOperationException::class);
