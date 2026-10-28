<?php

use App\Enums\OrganizationRole;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Automations\TemplateRenderer;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'name' => 'Acme & Sons',
        'timezone' => 'Europe/Amsterdam',
        'business_hours' => [
            'timezone' => 'Europe/Amsterdam',
            'days' => ['mon' => [['start' => '09:00', 'end' => '17:00']], 'tue' => [['start' => '09:00', 'end' => '17:00']], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []],
            'holidays' => ['2026-11-03'],
        ],
    ]);
    $this->assignee = User::factory()->create(['name' => 'Grace Hopper']);
    $this->organization->members()->attach($this->assignee, ['role' => OrganizationRole::Admin->value]);

    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Support']);
    $this->conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'subject' => 'Order <#42>', 'assignee_id' => $this->assignee->id]);
    $this->message = Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'from' => ['name' => 'Ada <b>Lovelace</b>', 'email' => 'ada@customer.example'],
    ]);

    $this->renderer = app(TemplateRenderer::class);
});

afterEach(fn () => Carbon::setTestNow());

test('variables are substituted and html escaped', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-30 14:05', 'Europe/Amsterdam'));

    $rendered = $this->renderer->render(
        '<p>Hi {{ contact.first_name }} ({{contact.name}}, {{ CONTACT.EMAIL }}) about {{ conversation.subject }} in {{ inbox.name }} at {{ organization.name }}. Agent: {{ assignee.first_name }} / {{ assignee.name }}. Ref {{ conversation.id }}. Back on {{ next_business_day }}. Today {{ date }} {{ time }}. {{ unknown }}{{ also.unknown }}</p>',
        $this->conversation,
        ['message' => $this->message],
    );

    expect($rendered)
        ->toContain('Hi Ada (Ada &lt;b&gt;Lovelace&lt;/b&gt;, ada@customer.example)')
        ->toContain('about Order &lt;#42&gt; in Support at Acme &amp; Sons.')
        ->toContain('Agent: Grace / Grace Hopper.')
        ->toContain('Ref '.$this->conversation->uuid.'.')
        ->toContain('Back on Monday November 2, 2026.')
        ->toContain('Today October 30, 2026 2:05 PM.')
        ->toEndWith('. </p>')
        ->not->toContain('<b>');
});

test('next business day skips closed days and holidays', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'Europe/Amsterdam'));

    expect($this->renderer->variables($this->conversation)['next_business_day'])->toBe('Monday November 9, 2026');
});

test('missing data renders as empty strings', function () {
    $conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'subject' => null, 'participants' => []]);

    $variables = $this->renderer->variables($conversation);

    expect($variables['contact.name'])->toBe('')
        ->and($variables['contact.email'])->toBe('')
        ->and($variables['assignee.name'])->toBe('')
        ->and($variables['conversation.subject'])->toBe('')
        ->and($this->renderer->render('', $conversation))->toBe('')
        ->and($this->renderer->render(null, $conversation))->toBe('');
});

test('the contact falls back to conversation participants without messages', function () {
    $conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'participants' => [['name' => 'Linus T', 'email' => 'linus@kernel.example']]]);

    expect($this->renderer->render('{{ contact.name }} <{{ contact.email }}>', $conversation))->toBe('Linus T <linus@kernel.example>');
});
