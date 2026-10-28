<?php

use App\Enums\SyncRunType;
use App\Models\Conversation;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\SyncRun;
use App\Services\Mail\Data\MailboxState;
use App\Services\Mail\Ingestion\MessageIngestor;
use App\Services\Mail\Ingestion\MimeMessageParser;
use App\Services\Mail\Providers\Gmail\GmailProvider;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGmailApi;

function gmailFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/mail/gmail/{$name}")), true);
}

beforeEach(function () {
    Storage::fake('local');
    config()->set('tandem.attachments.disk', 'local');

    $this->account = MailAccount::factory()->gmail()->create(['email_address' => 'support@acme.test', 'sync_state' => ['history_id' => '1000']]);
    $this->run = SyncRun::start($this->account, SyncRunType::Incremental);
});

it('ingests messages added since the stored history id and advances the cursor', function () {
    $api = FakeGmailApi::install()
        ->json(gmailFixture('history-message-added.json'))
        ->rawMessage('msg-1', 'thread-1', ['INBOX', 'UNREAD'], file_get_contents(base_path('tests/Fixtures/mail/simple.eml')));

    $result = app(GmailProvider::class)->incrementalSync($this->account, $this->run);

    expect($result->created)->toBe(1)
        ->and($result->fetched)->toBe(1)
        ->and($result->hasMore)->toBeFalse()
        ->and($this->account->fresh()->syncState('history_id'))->toBe('1010');

    $message = Message::query()->where('provider_message_id', 'msg-1')->firstOrFail();
    expect($message->provider_thread_id)->toBe('thread-1')
        ->and($message->subject)->toBe('Analytical engine order #42')
        ->and($message->is_read)->toBeFalse()
        ->and($message->provider_labels)->toBe(['INBOX', 'UNREAD']);

    $conversation = $message->conversation;
    expect($conversation->provider_thread_id)->toBe('thread-1')
        ->and($conversation->in_inbox)->toBeTrue()
        ->and($conversation->unread_count)->toBe(1)
        ->and(Conversation::count())->toBe(1);

    expect($api->request(0)->getMethod())->toBe('GET')
        ->and((string) $api->request(0)->getUri())->toContain('/gmail/v1/users/me/history', 'startHistoryId=1000', 'historyTypes=messageAdded', 'historyTypes=messageDeleted')
        ->and((string) $api->request(1)->getUri())->toContain('/gmail/v1/users/me/messages/msg-1', 'format=raw')
        ->and($api->request(1)->getHeaderLine('Authorization'))->toBe('Bearer '.$this->account->credential('access_token'));
});

it('moves an existing message to trash when Gmail reports INBOX removed and TRASH added', function () {
    $incoming = app(MimeMessageParser::class)->parse(file_get_contents(base_path('tests/Fixtures/mail/simple.eml')), [
        'providerMessageId' => 'msg-1',
        'providerThreadId' => 'thread-1',
        'labels' => ['INBOX', 'UNREAD'],
        'state' => MailboxState::fromGmailLabels(['INBOX', 'UNREAD']),
    ]);
    $existing = app(MessageIngestor::class)->ingest($this->account, $incoming);
    expect($existing->conversation->in_inbox)->toBeTrue();

    $api = FakeGmailApi::install()
        ->json(gmailFixture('history-label-change.json'))
        ->minimalMessage('msg-1', 'thread-1', ['TRASH']);

    $result = app(GmailProvider::class)->incrementalSync($this->account, $this->run);

    $message = $existing->message->fresh();
    $conversation = $existing->conversation->fresh();

    expect($result->updated)->toBe(1)
        ->and($result->created)->toBe(0)
        ->and(Message::count())->toBe(1)
        ->and($message->mailbox_state['isTrashed'])->toBeTrue()
        ->and($message->mailbox_state['inInbox'])->toBeFalse()
        ->and($message->provider_labels)->toBe(['TRASH'])
        ->and($conversation->is_trashed)->toBeTrue()
        ->and($conversation->in_inbox)->toBeFalse()
        ->and($this->account->fresh()->syncState('history_id'))->toBe('1020');

    expect($api->requests)->toHaveCount(2)
        ->and((string) $api->request(1)->getUri())->toContain('/messages/msg-1', 'format=minimal');
});

it('soft deletes a message that Gmail reports as permanently deleted', function () {
    $incoming = app(MimeMessageParser::class)->parse(file_get_contents(base_path('tests/Fixtures/mail/simple.eml')), ['providerMessageId' => 'msg-1', 'providerThreadId' => 'thread-1']);
    $existing = app(MessageIngestor::class)->ingest($this->account, $incoming);

    FakeGmailApi::install()->json([
        'history' => [['id' => '1005', 'messagesDeleted' => [['message' => ['id' => 'msg-1', 'threadId' => 'thread-1']]]]],
        'historyId' => '1030',
    ]);

    $result = app(GmailProvider::class)->incrementalSync($this->account, $this->run);

    expect($result->deleted)->toBe(1)
        ->and(Message::withTrashed()->find($existing->message->id)->trashed())->toBeTrue()
        ->and($existing->conversation->fresh()->message_count)->toBe(0);
});

it('reconciles recent mail and resets the cursor when the history id is too old', function () {
    $api = FakeGmailApi::install()
        ->error(404, 'notFound', 'Requested entity was not found.')
        ->json(['emailAddress' => 'support@acme.test', 'historyId' => '5000', 'messagesTotal' => 1])
        ->json(['messages' => [['id' => 'msg-7', 'threadId' => 'thread-7']], 'resultSizeEstimate' => 1])
        ->rawMessage('msg-7', 'thread-7', ['INBOX'], file_get_contents(base_path('tests/Fixtures/mail/simple.eml')));

    $result = app(GmailProvider::class)->incrementalSync($this->account, $this->run);

    $account = $this->account->fresh();

    expect($result->created)->toBe(1)
        ->and($result->hasMore)->toBeFalse()
        ->and($account->syncState('history_id'))->toBe('5000')
        ->and($account->syncState('reconcile_page_token'))->toBeNull()
        ->and(Message::query()->where('provider_message_id', 'msg-7')->exists())->toBeTrue();

    expect((string) $api->request(1)->getUri())->toContain('/gmail/v1/users/me/profile')
        ->and((string) $api->request(2)->getUri())->toContain('/gmail/v1/users/me/messages', 'includeSpamTrash=true', 'q=after')
        ->and((string) $api->request(3)->getUri())->toContain('/messages/msg-7', 'format=raw');
});
