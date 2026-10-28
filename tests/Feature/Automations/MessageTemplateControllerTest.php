<?php

use App\Enums\OrganizationRole;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();

    $this->organization = Organization::factory()->create(['name' => 'Acme']);
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->organization->members()->attach($this->admin, ['role' => OrganizationRole::Owner->value]);
    $this->organization->members()->attach($this->member, ['role' => OrganizationRole::Member->value]);

    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Support']);
    $this->template = MessageTemplate::create(['organization_id' => $this->organization->id, 'name' => 'Welcome', 'subject' => 'Hello', 'body_html' => '<p>Welcome</p>']);

    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, ...$parameters]);
});

test('admins can list templates while members are forbidden', function () {
    $this->actingAs($this->admin)
        ->get(($this->url)('templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/templates/Index', false)
            ->has('templates', 1, fn (Assert $template) => $template->where('name', 'Welcome')->where('inbox', null)->etc())
            ->has('inboxes', 1)
            ->has('variables', 12));

    $this->actingAs($this->member)->get(($this->url)('templates.index'))->assertForbidden();
    $this->actingAs($this->member)->post(($this->url)('templates.store'), ['name' => 'x', 'body_html' => '<p>x</p>'])->assertForbidden();
});

test('templates can be created updated and deleted with sanitized html', function () {
    $this->actingAs($this->admin)
        ->post(($this->url)('templates.store'), [
            'name' => 'Out of office',
            'category' => 'ooo',
            'subject' => 'Re: {{ conversation.subject }}',
            'body_html' => '<p onclick="x()">Hi {{ contact.first_name }}</p><script>alert(1)</script>',
            'inbox_uuid' => $this->inbox->uuid,
        ])
        ->assertRedirect(($this->url)('templates.index'));

    $template = MessageTemplate::query()->where('name', 'Out of office')->first();

    expect($template->inbox_id)->toBe($this->inbox->id)
        ->and($template->created_by_id)->toBe($this->admin->id)
        ->and($template->body_html)->toContain('Hi {{ contact.first_name }}')
        ->and($template->body_html)->not->toContain('<script')
        ->and($template->body_html)->not->toContain('onclick');

    $this->actingAs($this->admin)
        ->put(($this->url)('templates.update', ['template' => $template]), ['name' => 'Renamed', 'body_html' => '<p>Changed</p>', 'inbox_uuid' => null])
        ->assertRedirect();

    expect($template->fresh()->name)->toBe('Renamed')
        ->and($template->fresh()->inbox_id)->toBeNull();

    $this->actingAs($this->admin)
        ->post(($this->url)('templates.store'), ['name' => '', 'body_html' => '', 'inbox_uuid' => Inbox::factory()->create()->uuid])
        ->assertSessionHasErrors(['name', 'body_html', 'inbox_uuid']);

    $foreign = MessageTemplate::create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Foreign', 'body_html' => '<p>x</p>']);
    $this->actingAs($this->admin)->delete(($this->url)('templates.destroy', ['template' => $foreign]))->assertNotFound();

    $this->actingAs($this->admin)->delete(($this->url)('templates.destroy', ['template' => $template]))->assertRedirect();
    expect(MessageTemplate::query()->whereKey($template->id)->exists())->toBeFalse();
});

test('preview renders variables for a conversation', function () {
    $conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'subject' => 'Refund']);
    Message::factory()->create(['conversation_id' => $conversation->id, 'from' => ['name' => 'Ada Lovelace', 'email' => 'ada@customer.example']]);

    $response = $this->actingAs($this->admin)
        ->postJson(($this->url)('templates.preview'), [
            'subject' => 'Re: {{ conversation.subject }}',
            'body_html' => '<p>Hi {{ contact.first_name }} from {{ organization.name }}<script>x</script></p>',
            'conversation_uuid' => $conversation->uuid,
        ])
        ->assertOk()
        ->assertJsonPath('subject', 'Re: Refund')
        ->assertJsonPath('conversation.uuid', $conversation->uuid);

    expect($response->json('variables')['contact.email'])->toBe('ada@customer.example')
        ->and($response->json('body_html'))->toContain('Hi Ada from Acme')
        ->and($response->json('body_html'))->not->toContain('<script');

    $this->actingAs($this->admin)
        ->postJson(($this->url)('templates.preview'), ['body_html' => '<p>Hi {{ contact.first_name }}</p>', 'conversation_uuid' => Conversation::factory()->create()->uuid])
        ->assertUnprocessable();
});
