<?php

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Events\Mail\MessageIngested;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\MailAccount;
use App\Models\Message;
use App\Services\Mail\Data\MailboxState;
use App\Services\Mail\Ingestion\MessageIngestor;
use App\Services\Mail\Ingestion\MimeMessageParser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

function mailFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/mail/{$name}"));
}

beforeEach(function () {
    Storage::fake('local');
    config()->set('tandem.attachments.disk', 'local');

    $this->account = MailAccount::factory()->create(['email_address' => 'support@acme.test']);
    $this->parser = app(MimeMessageParser::class);
    $this->ingestor = app(MessageIngestor::class);
});

test('a raw mime message becomes a sanitized message with attachments and a conversation', function () {
    Event::fake([MessageIngested::class]);

    $incoming = $this->parser->parse(mailFixture('simple.eml'), ['providerMessageId' => 'gm-1', 'providerThreadId' => 'thread-1']);
    $result = $this->ingestor->ingest($this->account, $incoming);

    expect($result->created)->toBeTrue()
        ->and($result->createdConversation)->toBeTrue()
        ->and($result->message->direction)->toBe(MessageDirection::Inbound)
        ->and($result->message->subject)->toBe('Analytical engine order #42')
        ->and($result->message->from['email'])->toBe('ada@example.org')
        ->and($result->message->from['name'])->toBe('Ada Lovelace')
        ->and($result->message->cc[0]['email'])->toBe('charles@example.org')
        ->and($result->message->html_body)->not->toContain('<script')
        ->and($result->message->html_body)->not->toContain('onclick')
        ->and($result->message->html_body)->toContain('target="_blank"')
        ->and($result->message->html_body)->toContain('cid:logo@example.org')
        ->and($result->message->text_body)->toContain('Analytical Engine')
        ->and($result->message->has_attachments)->toBeTrue()
        ->and($result->message->raw_path)->not->toBeNull();

    $conversation = $result->conversation;

    expect($conversation->subject)->toBe('Analytical engine order #42')
        ->and($conversation->normalized_subject)->toBe('analytical engine order #42')
        ->and($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->message_count)->toBe(1)
        ->and($conversation->unread_count)->toBe(1)
        ->and($conversation->in_inbox)->toBeTrue()
        ->and($conversation->snippet)->toStartWith('Hello team')
        ->and(collect($conversation->participants)->pluck('email'))->toContain('ada@example.org', 'charles@example.org');

    expect(Attachment::count())->toBe(2);

    $inline = Attachment::where('content_id', 'logo@example.org')->first();
    expect($inline->is_inline)->toBeTrue()
        ->and($inline->mime_type)->toBe('image/png')
        ->and(Storage::disk('local')->exists($inline->path))->toBeTrue();

    $this->assertDatabaseHas('contacts', ['email' => 'ada@example.org', 'name' => 'Ada Lovelace', 'organization_id' => $this->account->organization_id]);
    $this->assertDatabaseHas('conversation_events', ['conversation_id' => $conversation->id, 'type' => 'message_received']);

    Event::assertDispatched(MessageIngested::class, fn (MessageIngested $event) => $event->isNewConversation);
});

test('ingesting the same message twice is idempotent and refreshes flags', function () {
    $incoming = $this->parser->parse(mailFixture('simple.eml'), ['providerMessageId' => 'gm-1']);
    $first = $this->ingestor->ingest($this->account, $incoming);

    $again = $this->ingestor->ingest($this->account, $incoming->with(['state' => new MailboxState(inInbox: false, isArchived: true, isRead: true)]));

    expect($again->created)->toBeFalse()
        ->and($again->message->is($first->message))->toBeTrue()
        ->and(Message::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and($again->conversation->fresh()->is_archived)->toBeTrue()
        ->and($again->conversation->fresh()->unread_count)->toBe(0);
});

test('replies thread by In-Reply-To and outbound mail from the account is detected', function () {
    $this->ingestor->ingest($this->account, $this->parser->parse(mailFixture('simple.eml')));
    $reply = $this->ingestor->ingest($this->account, $this->parser->parse(mailFixture('reply.eml')));

    expect($reply->createdConversation)->toBeFalse()
        ->and($reply->message->direction)->toBe(MessageDirection::Outbound)
        ->and($reply->message->is_read)->toBeTrue()
        ->and(Conversation::count())->toBe(1)
        ->and($reply->conversation->message_count)->toBe(2)
        ->and($reply->conversation->last_outbound_at)->not->toBeNull()
        ->and($reply->conversation->snippet)->toStartWith('Hi Ada, yes we have one in stock');
});

test('replies without threading headers fall back to subject and participant matching', function () {
    $this->ingestor->ingest($this->account, $this->parser->parse(mailFixture('simple.eml')));
    $reply = $this->ingestor->ingest($this->account, $this->parser->parse(mailFixture('subject-only-reply.eml')));

    expect($reply->createdConversation)->toBeFalse()
        ->and(Conversation::count())->toBe(1);
});

test('a new inbound reply reopens a closed conversation', function () {
    Event::fake([MessageIngested::class]);

    $first = $this->ingestor->ingest($this->account, $this->parser->parse(mailFixture('simple.eml')));
    $first->conversation->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()])->save();

    $reply = $this->ingestor->ingest($this->account, $this->parser->parse(mailFixture('subject-only-reply.eml')));

    expect($reply->conversation->status)->toBe(ConversationStatus::Open);
    Event::assertDispatched(MessageIngested::class, fn (MessageIngested $event) => $event->reopened === true && ! $event->isNewConversation);
});

test('messages from different accounts never share conversations', function () {
    $other = MailAccount::factory()->create(['email_address' => 'sales@other.test']);

    $this->ingestor->ingest($this->account, $this->parser->parse(mailFixture('simple.eml')));
    $this->ingestor->ingest($other, $this->parser->parse(mailFixture('reply.eml')));

    expect(Conversation::count())->toBe(2);
});
