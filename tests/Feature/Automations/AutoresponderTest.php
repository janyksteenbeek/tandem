<?php

use App\Enums\AutomationTrigger;
use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\OrganizationRole;
use App\Jobs\Mail\SendOutgoingMessage;
use App\Models\AutomationRule;
use App\Models\AutoresponderSend;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Automations\AutomationEngine;
use App\Services\Mail\Data\OutgoingMessage;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([SendOutgoingMessage::class]);

    $this->organization = Organization::factory()->create(['name' => 'Acme']);
    $this->owner = User::factory()->create();
    $this->organization->members()->attach($this->owner, ['role' => OrganizationRole::Owner->value]);

    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Support', 'email_address' => 'hello@acme.test']);
    $this->account = MailAccount::factory()->create(['inbox_id' => $this->inbox->id, 'email_address' => 'support@acme.test']);

    $this->template = MessageTemplate::create([
        'organization_id' => $this->organization->id,
        'name' => 'Out of office',
        'subject' => 'Re: {{ conversation.subject }} - we got it',
        'body_html' => '<p>Hi {{ contact.first_name }}, thanks for writing to {{ organization.name }}.</p>',
    ]);

    $this->rule = AutomationRule::factory()->create([
        'organization_id' => $this->organization->id,
        'created_by_id' => $this->owner->id,
        'trigger' => AutomationTrigger::ConversationReceived,
        'actions' => [['type' => 'reply_with_template', 'params' => ['template_id' => $this->template->id]]],
    ]);

    $this->conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'mail_account_id' => $this->account->id, 'subject' => 'Need help']);

    $this->inbound = fn (array $attributes = []) => Message::factory()->create(array_merge([
        'conversation_id' => $this->conversation->id,
        'mail_account_id' => $this->account->id,
        'from' => ['name' => 'Ada Lovelace', 'email' => 'ada@customer.example'],
        'to' => [['name' => null, 'email' => 'support@acme.test']],
        'headers' => [],
    ], $attributes));

    $this->run = fn (Message $message) => app(AutomationEngine::class)->run(AutomationTrigger::ConversationReceived, $this->conversation, ['message' => $message]);
});

test('an automated reply is queued with auto-submitted headers and recorded in the ledger', function () {
    $message = ($this->inbound)();

    ($this->run)($message);

    $reply = Message::query()->where('conversation_id', $this->conversation->id)->where('direction', MessageDirection::Outbound->value)->first();

    expect($reply)->not->toBeNull()
        ->and($reply->to[0]['email'])->toBe('ada@customer.example')
        ->and($reply->from['email'])->toBe('support@acme.test')
        ->and($reply->author_id)->toBe($this->owner->id)
        ->and($reply->subject)->toBe('Re: Need help - we got it')
        ->and($reply->html_body)->toContain('Hi Ada, thanks for writing to Acme.')
        ->and($reply->in_reply_to)->toBe($message->message_id_header)
        ->and($reply->delivery_status)->toBe(DeliveryStatus::Queued)
        ->and($reply->headers['auto-submitted'])->toBe('auto-replied')
        ->and($reply->headers['x-tandem-autoresponse'])->toBe($this->rule->uuid);

    Bus::assertDispatched(SendOutgoingMessage::class, fn (SendOutgoingMessage $job) => $job->message->is($reply));

    $this->assertDatabaseHas('autoresponder_sends', ['automation_rule_id' => $this->rule->id, 'conversation_id' => $this->conversation->id, 'recipient_email' => 'ada@customer.example']);
    $this->assertDatabaseCount('drafts', 0);

    $run = $this->rule->runs()->first();
    expect($run->status)->toBe('matched')
        ->and($run->details['actions'][0]['status'])->toBe('sent')
        ->and($run->details['actions'][0]['recipient'])->toBe('ada@customer.example');

    $outgoing = (new ReflectionMethod(SendOutgoingMessage::class, 'buildOutgoing'))->invoke(new SendOutgoingMessage($reply), $reply->fresh(['conversation', 'mailAccount', 'attachments']));

    expect($outgoing)->toBeInstanceOf(OutgoingMessage::class)
        ->and($outgoing->headers['X-Tandem-Message'])->toBe($reply->uuid)
        ->and($outgoing->headers['Auto-Submitted'])->toBe('auto-replied')
        ->and($outgoing->headers['X-Tandem-Autoresponse'])->toBe($this->rule->uuid);
});

test('the reply goes to the reply-to address when present', function () {
    ($this->run)(($this->inbound)(['reply_to' => [['name' => null, 'email' => 'ada.replies@customer.example']]]));

    $reply = Message::query()->where('direction', MessageDirection::Outbound->value)->first();

    expect($reply->to[0]['email'])->toBe('ada.replies@customer.example');
});

test('automated inbound mail never gets a reply', function (array $headers) {
    ($this->run)(($this->inbound)(['headers' => $headers]));

    expect(Message::query()->where('direction', MessageDirection::Outbound->value)->exists())->toBeFalse()
        ->and($this->rule->runs()->first()->details['actions'][0])->toMatchArray(['status' => 'skipped', 'reason' => 'auto_submitted']);

    Bus::assertNotDispatched(SendOutgoingMessage::class);
})->with([
    'auto-submitted' => [['auto-submitted' => 'auto-replied']],
    'precedence bulk' => [['precedence' => 'bulk']],
    'list-id' => [['list-id' => '<news.example.com>']],
    'suppress' => [['x-auto-response-suppress' => 'All']],
    'tandem marker' => [['x-tandem-autoresponse' => 'some-uuid']],
    'autoreply' => [['x-autoreply' => 'yes']],
]);

test('robot and bounce senders never get a reply', function (string $sender) {
    ($this->run)(($this->inbound)(['from' => ['name' => null, 'email' => $sender]]));

    expect(Message::query()->where('direction', MessageDirection::Outbound->value)->exists())->toBeFalse()
        ->and($this->rule->runs()->first()->details['actions'][0]['reason'])->toBe('blocked_sender');
})->with(['noreply@shop.example', 'no-reply@shop.example', 'MAILER-DAEMON@mx.example', 'postmaster@mx.example', 'bounces+123@mail.example']);

test('the organization never replies to its own addresses', function () {
    ($this->run)(($this->inbound)(['from' => ['name' => 'Support', 'email' => 'support@acme.test']]));

    expect(Message::query()->where('direction', MessageDirection::Outbound->value)->exists())->toBeFalse()
        ->and($this->rule->runs()->first()->details['actions'][0]['reason'])->toBe('own_address');

    ($this->run)(($this->inbound)(['from' => ['name' => 'Hello', 'email' => 'HELLO@acme.test']]));

    expect($this->rule->runs()->latest('id')->first()->details['actions'][0]['reason'])->toBe('own_address');
});

test('the cooldown ledger stops repeat replies to the same recipient', function () {
    AutoresponderSend::create([
        'automation_rule_id' => $this->rule->id,
        'conversation_id' => null,
        'recipient_email' => 'ada@customer.example',
        'sent_at' => now()->subHours(2),
    ]);

    ($this->run)(($this->inbound)());

    expect(Message::query()->where('direction', MessageDirection::Outbound->value)->exists())->toBeFalse()
        ->and($this->rule->runs()->first()->details['actions'][0]['reason'])->toBe('cooldown');

    $this->rule->update(['actions' => [['type' => 'reply_with_template', 'params' => ['template_id' => $this->template->id, 'cooldown_minutes' => 60]]]]);

    ($this->run)(($this->inbound)());

    expect(Message::query()->where('direction', MessageDirection::Outbound->value)->count())->toBe(1);
});

test('a reply within the same conversation respects the ledger after a real send', function () {
    ($this->run)(($this->inbound)());
    ($this->run)(($this->inbound)());

    expect(Message::query()->where('direction', MessageDirection::Outbound->value)->count())->toBe(1)
        ->and(AutoresponderSend::query()->count())->toBe(1)
        ->and($this->rule->runs()->latest('id')->first()->details['actions'][0]['reason'])->toBe('cooldown');
});

test('missing templates and senders are reported without sending', function () {
    $this->rule->update(['actions' => [['type' => 'reply_with_template', 'params' => ['template_id' => 999999]]]]);

    ($this->run)(($this->inbound)());

    expect($this->rule->runs()->first()->details['actions'][0]['reason'])->toBe('template_missing');

    $this->rule->update(['actions' => [['type' => 'reply_with_template', 'params' => ['template_id' => $this->template->id]]], 'created_by_id' => null]);
    $this->organization->memberships()->delete();

    ($this->run)(($this->inbound)());

    expect($this->rule->runs()->latest('id')->first()->details['actions'][0]['reason'])->toBe('no_sending_user');
    Bus::assertNotDispatched(SendOutgoingMessage::class);
});
