<?php

use App\Enums\Mail\InboxRole;
use App\Models\Mail\Attachment;
use App\Models\Mail\Conversation;
use App\Models\Mail\Inbox;
use App\Models\Mail\MailAccount;
use App\Models\Mail\Message;
use App\Models\Projects\IssueActivity;
use App\Models\Projects\Product;
use App\Services\Projects\ConversationIssueLinks;
use App\Services\Projects\IssueActions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ProjectsTestHelpers;

beforeEach(function () {
    Notification::fake();
    Queue::fake();
    Storage::fake('local');

    $w = ProjectsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];      // Engineering lead, NOT on the support inbox
    $this->other = $w['other'];        // Design member, on the support inbox
    $this->engineering = $w['engineering'];
    $this->design = $w['design'];
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);

    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Support']);
    $this->inbox->teams()->attach($this->design->id, ['role' => InboxRole::Agent->value]);
    $this->account = MailAccount::factory()->create(['inbox_id' => $this->inbox->id, 'organization_id' => $this->organization->id]);
    $this->conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'organization_id' => $this->organization->id, 'mail_account_id' => $this->account->id, 'subject' => 'Portfolio report calculates returns wrong']);
    $this->message = Message::factory()->create(['conversation_id' => $this->conversation->id, 'mail_account_id' => $this->account->id, 'html_body' => '<p>The report is <b>wrong</b>.<script>alert(1)</script></p>', 'text_body' => 'The report is wrong.']);

    $this->actions = app(IssueActions::class);
    $this->links = app(ConversationIssueLinks::class);
    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, ...$parameters]);
});

test('support can create an issue from a conversation and the link shows on both sides without leaking content', function () {
    $response = $this->actingAs($this->other)->postJson(route('conversations.issues.store', $this->conversation), [
        'title' => 'Portfolio report calculates returns wrong',
        'product_id' => $this->product->id,
        'team_ids' => [$this->engineering->id, $this->design->id],
        'priority' => 'high',
    ])->assertCreated();

    $identifier = $response->json('issue.identifier');
    expect($identifier)->toBe('FRONT-1')
        ->and($response->json('conversation.linked_issues.0.identifier'))->toBe('FRONT-1');

    $this->assertDatabaseHas('issue_conversation_links', ['conversation_id' => $this->conversation->id, 'kind' => 'created_from']);
    $this->assertDatabaseHas('conversation_events', ['conversation_id' => $this->conversation->id, 'type' => 'issue_linked']);

    // The activity row references the conversation by uuid only.
    $activity = IssueActivity::query()->where('type', 'conversation_linked')->firstOrFail();
    expect(json_encode($activity->payload))->not->toContain('Portfolio report');

    // Engineering (no inbox access) can open the issue, sees a hidden link count, and nothing else.
    $this->actingAs($this->member)
        ->getJson(($this->url)('projects.issues.conversations.index', ['issue' => $identifier]))
        ->assertOk()
        ->assertJsonPath('hidden_count', 1)
        ->assertJsonCount(0, 'conversations');

    // Design (inbox agent) sees the conversation.
    $this->actingAs($this->other)
        ->getJson(($this->url)('projects.issues.conversations.index', ['issue' => $identifier]))
        ->assertOk()
        ->assertJsonPath('hidden_count', 0)
        ->assertJsonPath('conversations.0.subject', 'Portfolio report calculates returns wrong');
});

test('linking an existing issue requires access to the conversation', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Existing', 'product' => $this->product], $this->admin);

    $this->actingAs($this->member)->postJson(route('conversations.issues.store', $this->conversation), ['identifier' => $issue->identifier])->assertForbidden();

    $this->actingAs($this->other)->postJson(route('conversations.issues.store', $this->conversation), ['identifier' => $issue->identifier])->assertCreated();
    $this->assertDatabaseHas('issue_conversation_links', ['issue_id' => $issue->id, 'conversation_id' => $this->conversation->id, 'kind' => 'linked']);

    // Linking twice is a no-op.
    $this->actingAs($this->other)->postJson(route('conversations.issues.store', $this->conversation), ['identifier' => $issue->identifier])->assertCreated();
    expect($issue->fresh()->link_count)->toBe(1);

    $this->actingAs($this->member)->deleteJson(route('conversations.issues.destroy', [$this->conversation, $issue->identifier]))->assertForbidden();
    $this->actingAs($this->other)->deleteJson(route('conversations.issues.destroy', [$this->conversation, $issue->identifier]))->assertOk();
    expect($issue->fresh()->link_count)->toBe(0);
});

test('sharing an excerpt copies sanitised content into the issue and is audited', function () {
    $issue = $this->actions->create($this->organization, ['title' => 'Bug', 'product' => $this->product], $this->admin);

    $this->actingAs($this->member)
        ->postJson(route('conversations.issues.share', $this->conversation), ['identifier' => $issue->identifier, 'message_uuid' => $this->message->uuid])
        ->assertForbidden();

    $this->actingAs($this->other)
        ->postJson(route('conversations.issues.share', $this->conversation), ['identifier' => $issue->identifier, 'message_uuid' => $this->message->uuid])
        ->assertOk();

    $content = $issue->sharedContent()->firstOrFail();

    expect($content->kind->value)->toBe('excerpt')
        ->and($content->body_html)->toContain('<b>wrong</b>')
        ->and($content->body_html)->not->toContain('<script>');

    $this->assertDatabaseHas('audit_logs', ['action' => 'issue.content_shared']);
    $this->assertDatabaseHas('issue_conversation_links', ['issue_id' => $issue->id, 'conversation_id' => $this->conversation->id]);

    // Everyone who can see the issue sees the shared excerpt, even without inbox access.
    $this->actingAs($this->member)
        ->get(($this->url)('projects.issues.show', ['issue' => $issue->identifier]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('issue.shared_content', 1));
});

test('attachments are copied into the issue only when explicitly shared', function () {
    Storage::disk('local')->put('mail/1/x/report.pdf', 'pdf-bytes');
    $attachment = Attachment::query()->create([
        'message_id' => $this->message->id, 'filename' => 'report.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 9,
        'disk' => 'local', 'path' => 'mail/1/x/report.pdf',
    ]);

    $issue = $this->actions->create($this->organization, ['title' => 'With file', 'product' => $this->product], $this->admin);
    $this->links->link($issue, $this->conversation, $this->other);

    expect($issue->fresh()->attachment_count)->toBe(0);

    $this->actingAs($this->other)
        ->postJson(($this->url)('projects.issues.conversations.share-attachment', ['issue' => $issue->identifier, 'conversation' => $this->conversation->uuid]), ['attachment_uuid' => $attachment->uuid])
        ->assertOk();

    $copy = $issue->attachments()->firstOrFail();

    expect($issue->fresh()->attachment_count)->toBe(1)
        ->and($copy->source_attachment_id)->toBe($attachment->id)
        ->and(Storage::disk('local')->exists($copy->path))->toBeTrue();

    $this->actingAs($this->member)->get(route('projects.attachments.show', $copy->uuid))->assertOk();
});

test('personal inbox conversations cannot be linked to issues', function () {
    $personal = Inbox::factory()->personal($this->other)->create();
    $conversation = Conversation::factory()->create(['inbox_id' => $personal->id, 'organization_id' => null]);

    $this->actingAs($this->other)->postJson(route('conversations.issues.store', $conversation), ['title' => 'Nope'])->assertStatus(422);
});
