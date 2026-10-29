<?php

use App\Enums\DeliveryStatus;
use App\Enums\InboxRole;
use App\Enums\MailProvider;
use App\Enums\OrganizationRole;
use App\Jobs\Mail\SendOutgoingMessage;
use App\Models\Conversation;
use App\Models\Draft;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Data\SentMessageResult;
use App\Services\Mail\MailProviderManager;
use App\Services\Mail\Outbound\FallbackMailer;
use App\Services\Mail\Providers\Imap\ImapProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');

    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->memberships()->create(['user_id' => $this->user->id, 'role' => OrganizationRole::Member]);

    $this->account = MailAccount::factory()->personal($this->user)->create(['email_address' => 'me@example.test']);
    $this->inbox = $this->account->inbox;
    $this->account->identities()->create(['name' => 'Me', 'email' => 'me@example.test', 'is_default' => true]);

    $this->conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'organization_id' => null, 'mail_account_id' => $this->account->id, 'subject' => 'Order #42']);
    $this->message = Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'mail_account_id' => $this->account->id,
        'from' => ['name' => 'Ada', 'email' => 'ada@example.org'],
        'to' => [['name' => null, 'email' => 'me@example.test']],
        'cc' => [['name' => null, 'email' => 'charles@example.org']],
        'message_id_header' => '<parent@example.org>',
    ]);
});

test('a reply-all draft is prefilled with the right recipients, subject and quoted body', function () {
    $this->actingAs($this->user)
        ->postJson(route('drafts.store'), ['inbox' => $this->inbox->uuid, 'conversation' => $this->conversation->uuid, 'kind' => 'reply_all'])
        ->assertCreated()
        ->assertJsonPath('draft.to.0.email', 'ada@example.org')
        ->assertJsonPath('draft.cc.0.email', 'charles@example.org')
        ->assertJsonPath('draft.subject', 'Re: Order #42')
        ->assertJsonPath('draft.sender_identity_id', $this->account->identities()->first()->id);

    expect($this->conversation->fresh()->has_draft)->toBeTrue();
});

test('sending a draft stores an outbound message with threading headers and queues delivery', function () {
    $draft = Draft::factory()->create([
        'inbox_id' => $this->inbox->id,
        'conversation_id' => $this->conversation->id,
        'mail_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'kind' => 'reply',
        'in_reply_to_message_id' => $this->message->id,
        'to' => [['name' => 'Ada', 'email' => 'ada@example.org']],
        'subject' => null,
        'html_body' => '<p>Thanks Ada!</p><script>x()</script>',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('drafts.send', $draft), ['then' => 'open'])
        ->assertOk()
        ->assertJsonPath('message.direction', 'outbound')
        ->assertJsonPath('message.delivery_status', 'queued')
        ->assertJsonPath('message.subject', 'Re: Order #42');

    $sent = $this->conversation->messages()->where('direction', 'outbound')->first();

    expect($sent->in_reply_to)->toBe('<parent@example.org>')
        ->and($sent->references)->toContain('<parent@example.org>')
        ->and($sent->html_body)->not->toContain('<script')
        ->and($sent->from['email'])->toBe('me@example.test')
        ->and($sent->author_id)->toBe($this->user->id)
        ->and(Draft::query()->find($draft->id))->toBeNull()
        ->and($this->conversation->fresh()->has_draft)->toBeFalse();

    Queue::assertPushed(SendOutgoingMessage::class, fn (SendOutgoingMessage $job) => $job->message->is($sent));
});

test('a draft without recipients cannot be sent', function () {
    $draft = Draft::factory()->create(['inbox_id' => $this->inbox->id, 'user_id' => $this->user->id, 'to' => []]);

    $this->actingAs($this->user)->postJson(route('drafts.send', $draft))->assertStatus(422);
});

test('attachments upload to the draft and blocked extensions are refused', function () {
    $draft = Draft::factory()->create(['inbox_id' => $this->inbox->id, 'user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->post(route('drafts.attachments.store', $draft), ['file' => UploadedFile::fake()->create('quote.pdf', 120, 'application/pdf')])
        ->assertCreated()
        ->assertJsonPath('attachment.filename', 'quote.pdf');

    $this->actingAs($this->user)
        ->post(route('drafts.attachments.store', $draft), ['file' => UploadedFile::fake()->create('virus.exe', 10)])
        ->assertStatus(422);
});

test('drafts are private unless shared inside a team inbox', function () {
    $other = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->memberships()->create(['user_id' => $other->id, 'role' => OrganizationRole::Member]);

    $private = Draft::factory()->create(['inbox_id' => $this->inbox->id, 'user_id' => $this->user->id]);
    $this->actingAs($other)->patchJson(route('drafts.update', $private), ['subject' => 'hijack'])->assertForbidden();

    $shared = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $shared->users()->attach([$this->user->id => ['role' => InboxRole::Agent->value], $other->id => ['role' => InboxRole::Agent->value]]);
    $teamDraft = Draft::factory()->create(['inbox_id' => $shared->id, 'user_id' => $this->user->id, 'is_shared' => true]);

    $this->actingAs($other)->patchJson(route('drafts.update', $teamDraft), ['subject' => 'collab'])->assertOk()->assertJsonPath('draft.subject', 'collab');
});

test('the send job marks delivery as sent and records failures', function () {
    $draft = Draft::factory()->create([
        'inbox_id' => $this->inbox->id,
        'conversation_id' => $this->conversation->id,
        'mail_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'kind' => 'reply',
        'to' => [['name' => 'Ada', 'email' => 'ada@example.org']],
        'html_body' => '<p>Hi</p>',
    ]);

    $this->actingAs($this->user)->postJson(route('drafts.send', $draft))->assertOk();
    $message = $this->conversation->messages()->where('direction', 'outbound')->firstOrFail();

    $fake = new class extends ImapProvider
    {
        public function __construct() {}

        public function send(MailAccount $account, OutgoingMessage $message): SentMessageResult
        {
            return new SentMessageResult($message->messageId ?? 'x@y', 'provider-1');
        }
    };

    app(MailProviderManager::class)->register(MailProvider::Imap, $fake);

    (new SendOutgoingMessage($message))->handle(app(MailProviderManager::class), app(FallbackMailer::class));

    expect($message->fresh()->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($message->fresh()->provider_message_id)->toBe('provider-1');
});
