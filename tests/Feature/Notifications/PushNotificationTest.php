<?php

use App\Enums\Mail\InboxRole;
use App\Events\Mail\MessageIngested;
use App\Listeners\Mail\BroadcastIngestedMessage;
use App\Models\Mail\Conversation;
use App\Models\Mail\Inbox;
use App\Models\Mail\MailAccount;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Mail\ConversationAssignedNotification;
use App\Notifications\Mail\NewReplyNotification;
use App\Notifications\Mail\NewSharedMailNotification;
use App\Services\Mail\Ingestion\MessageIngestor;
use App\Services\Mail\Ingestion\MimeMessageParser;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use NotificationChannels\WebPush\WebPushChannel;

test('a notification adds the push channel only for topics the person has on', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['current_organization_id' => $organization->id]);
    $actor = User::factory()->create();
    $inbox = Inbox::factory()->create(['organization_id' => $organization->id]);
    $conversation = Conversation::factory()->create(['inbox_id' => $inbox->id, 'organization_id' => $organization->id]);

    $assigned = new ConversationAssignedNotification($conversation, $actor);

    expect($assigned->via($user))->toContain(WebPushChannel::class, 'database', 'broadcast');

    $user->forceFill(['preferences' => ['push' => ['direct' => false]]])->save();

    expect($assigned->via($user->fresh()))->toBe(['database', 'broadcast']);

    $push = $assigned->toWebPush($user, $assigned)->toArray();

    expect($push['title'])->toBe($actor->name.' assigned you a conversation')
        ->and($push['data']['url'])->toContain("/{$organization->slug}/mail/{$inbox->uuid}/all/{$conversation->uuid}")
        ->and($push['tag'])->toBe('conversation-'.$conversation->uuid);
});

test('a new conversation in a shared inbox pushes to its members who opted in', function () {
    Storage::fake('local');
    Notification::fake();

    $organization = Organization::factory()->create();
    $inbox = Inbox::factory()->create(['organization_id' => $organization->id]);
    $account = MailAccount::factory()->create(['inbox_id' => $inbox->id, 'email_address' => 'support@acme.test']);

    $subscriber = User::factory()->create(['preferences' => ['push' => ['shared' => true]]]);
    $quiet = User::factory()->create();
    $inbox->users()->attach([$subscriber->id => ['role' => InboxRole::Agent->value], $quiet->id => ['role' => InboxRole::Agent->value]]);

    $parser = app(MimeMessageParser::class);
    $result = app(MessageIngestor::class)->ingest($account, $parser->parse(file_get_contents(base_path('tests/Fixtures/mail/simple.eml'))));

    (new BroadcastIngestedMessage)->handle(new MessageIngested($result->message, $result->conversation, true));

    Notification::assertSentTo($subscriber, NewSharedMailNotification::class, fn ($notification, $channels) => $channels === [WebPushChannel::class]);
    Notification::assertNotSentTo($quiet, NewSharedMailNotification::class);
    Notification::assertNotSentTo($subscriber, NewReplyNotification::class);
});
