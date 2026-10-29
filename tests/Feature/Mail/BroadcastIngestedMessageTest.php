<?php

use App\Events\Broadcast\ConversationUpdated;
use App\Events\Broadcast\MessageAdded;
use App\Events\Broadcast\UserNotified;
use App\Events\Mail\MessageIngested;
use App\Listeners\Mail\BroadcastIngestedMessage;
use App\Models\MailAccount;
use App\Models\User;
use App\Notifications\Conversations\NewReplyNotification;
use App\Services\Mail\Ingestion\MessageIngestor;
use App\Services\Mail\Ingestion\MimeMessageParser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

test('an ingested message is broadcast to the inbox, the thread and the people who care', function () {
    Storage::fake('local');
    Notification::fake();

    $follower = User::factory()->create();
    $account = MailAccount::factory()->create(['email_address' => 'support@acme.test']);
    $parser = app(MimeMessageParser::class);
    $ingestor = app(MessageIngestor::class);

    $first = $ingestor->ingest($account, $parser->parse(file_get_contents(base_path('tests/Fixtures/mail/simple.eml'))));
    $first->conversation->followers()->attach($follower->id);

    Event::fake([ConversationUpdated::class, MessageAdded::class, UserNotified::class]);

    $reply = $ingestor->ingest($account, $parser->parse(file_get_contents(base_path('tests/Fixtures/mail/subject-only-reply.eml'))));

    (new BroadcastIngestedMessage)->handle(new MessageIngested($reply->message, $reply->conversation, false));

    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event) => $event->reason === 'message' && $event->conversation->is($reply->conversation));
    Event::assertDispatched(MessageAdded::class, fn (MessageAdded $event) => $event->message->is($reply->message));
    Event::assertDispatched(UserNotified::class, fn (UserNotified $event) => $event->userId === $follower->id);
    Notification::assertSentTo($follower, NewReplyNotification::class);

    $channels = (new ConversationUpdated($reply->conversation, 'message'))->broadcastOn();

    expect(collect($channels)->map(fn ($channel) => $channel->name)->all())
        ->toContain('private-inbox.'.$account->inbox_id, 'private-conversation.'.$reply->conversation->uuid);
});
