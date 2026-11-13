<?php

use App\Models\Projects\Product;
use App\Notifications\Projects\IssueCommentNotification;
use App\Notifications\Projects\IssueMentionedNotification;
use App\Services\Projects\IssueActions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProjectsTestHelpers;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');

    $w = ProjectsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->other = $w['other'];
    $this->outsider = $w['outsider'];
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $this->issue = app(IssueActions::class)->create($this->organization, ['title' => 'Discuss', 'product' => $this->product, 'owner' => $this->admin], $this->admin);
    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, ...$parameters]);
});

test('anyone who can see an issue can comment, mentions notify and followers are told', function () {
    $this->actingAs($this->member)
        ->postJson(($this->url)('projects.issues.comments.store', ['issue' => $this->issue->identifier]), [
            'body' => '<p>Hey <span class="mention" data-id="'.$this->other->id.'">@Other</span> look <script>x</script></p>',
            'mentions' => [$this->other->id, $this->outsider->id],
        ])
        ->assertCreated()
        ->assertJsonPath('comment.mentions', [$this->other->id])
        ->assertJsonPath('comment.user.id', $this->member->id);

    expect($this->issue->fresh()->comment_count)->toBe(1);

    Notification::assertSentTo($this->other, IssueMentionedNotification::class);
    Notification::assertSentTo($this->admin, IssueCommentNotification::class);
    Notification::assertNotSentTo($this->outsider, IssueMentionedNotification::class);
    Notification::assertNotSentTo($this->member, IssueCommentNotification::class);

    $this->assertDatabaseHas('issue_followers', ['issue_id' => $this->issue->id, 'user_id' => $this->member->id]);
});

test('outsiders cannot comment and only authors or managers delete', function () {
    $this->actingAs($this->outsider)
        ->postJson(($this->url)('projects.issues.comments.store', ['issue' => $this->issue->identifier]), ['body' => '<p>hi</p>'])
        ->assertForbidden();

    $comment = $this->issue->comments()->create(['user_id' => $this->member->id, 'body_html' => '<p>mine</p>', 'body_text' => 'mine']);

    $this->actingAs($this->other)->deleteJson(($this->url)('projects.comments.destroy', ['comment' => $comment->uuid]))->assertForbidden();
    $this->actingAs($this->other)->patchJson(($this->url)('projects.comments.update', ['comment' => $comment->uuid]), ['body' => '<p>edited</p>'])->assertForbidden();
    $this->actingAs($this->admin)->deleteJson(($this->url)('projects.comments.destroy', ['comment' => $comment->uuid]))->assertOk();
});

test('reactions toggle per user and emoji', function () {
    $comment = $this->issue->comments()->create(['user_id' => $this->member->id, 'body_html' => '<p>nice</p>', 'body_text' => 'nice']);

    $this->actingAs($this->other)
        ->postJson(($this->url)('projects.comments.react', ['comment' => $comment->uuid]), ['emoji' => '🎉', 'on' => true])
        ->assertOk()
        ->assertJsonPath('comment.reactions.0.emoji', '🎉')
        ->assertJsonPath('comment.reactions.0.count', 1)
        ->assertJsonPath('comment.reactions.0.mine', true);

    $this->actingAs($this->other)
        ->postJson(($this->url)('projects.comments.react', ['comment' => $comment->uuid]), ['emoji' => '🎉', 'on' => false])
        ->assertOk()
        ->assertJsonCount(0, 'comment.reactions');
});

test('attachments are stored privately, blocked by extension and served only to viewers', function () {
    $this->actingAs($this->member)
        ->postJson(($this->url)('projects.issues.attachments.store', ['issue' => $this->issue->identifier]), ['file' => UploadedFile::fake()->create('malware.exe', 10)])
        ->assertStatus(422);

    $response = $this->actingAs($this->member)
        ->postJson(($this->url)('projects.issues.attachments.store', ['issue' => $this->issue->identifier]), ['file' => UploadedFile::fake()->image('screen.png')])
        ->assertCreated();

    $uuid = $response->json('attachment.uuid');
    expect($this->issue->fresh()->attachment_count)->toBe(1);

    $this->actingAs($this->other)->get(route('projects.attachments.show', $uuid))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($this->outsider)->get(route('projects.attachments.show', $uuid))->assertForbidden();

    $this->actingAs($this->other)->deleteJson(($this->url)('projects.attachments.destroy', ['attachment' => $uuid]))->assertForbidden();
    $this->actingAs($this->member)->deleteJson(($this->url)('projects.attachments.destroy', ['attachment' => $uuid]))->assertOk();
    expect($this->issue->fresh()->attachment_count)->toBe(0);
});
