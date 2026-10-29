<?php

use App\Enums\InboxRole;
use App\Enums\OrganizationRole;
use App\Jobs\Mail\SendOutgoingMessage;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Conversations\MentionedInCommentNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Notification::fake();

    $this->organization = Organization::factory()->create();
    $this->agent = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->teammate = User::factory()->create(['current_organization_id' => $this->organization->id]);
    $this->organization->memberships()->create(['user_id' => $this->agent->id, 'role' => OrganizationRole::Member]);
    $this->organization->memberships()->create(['user_id' => $this->teammate->id, 'role' => OrganizationRole::Member]);

    $team = Team::factory()->create(['organization_id' => $this->organization->id]);
    $team->members()->attach([$this->agent->id, $this->teammate->id]);

    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $this->inbox->teams()->attach($team->id, ['role' => InboxRole::Agent->value]);
    $this->conversation = Conversation::factory()->create(['inbox_id' => $this->inbox->id, 'organization_id' => $this->organization->id]);
});

test('comments stay internal, notify mentions and never become outbound mail', function () {
    $this->actingAs($this->agent)
        ->postJson(route('conversations.comments.store', $this->conversation), [
            'body' => '<p>Can you look at this <script>alert(1)</script>?</p>',
            'mentions' => [$this->teammate->id],
        ])
        ->assertCreated()
        ->assertJsonPath('comment.user.id', $this->agent->id)
        ->assertJsonPath('comment.mentions.0', $this->teammate->id);

    $comment = $this->conversation->comments()->first();

    expect($comment->body)->not->toContain('<script')
        ->and($this->conversation->fresh()->comment_count)->toBe(1)
        ->and($this->conversation->messages()->count())->toBe(0);

    Notification::assertSentTo($this->teammate, MentionedInCommentNotification::class);
    Queue::assertNotPushed(SendOutgoingMessage::class);

    $this->assertDatabaseHas('conversation_followers', ['conversation_id' => $this->conversation->id, 'user_id' => $this->teammate->id]);
});

test('mentions of people without access are dropped', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->agent)
        ->postJson(route('conversations.comments.store', $this->conversation), ['body' => 'hello', 'mentions' => [$stranger->id]])
        ->assertCreated()
        ->assertJsonPath('comment.mentions', []);

    Notification::assertNothingSent();
});

test('only the author can edit a comment and outsiders cannot read them', function () {
    $this->actingAs($this->agent)->postJson(route('conversations.comments.store', $this->conversation), ['body' => 'first'])->assertCreated();
    $comment = $this->conversation->comments()->first();

    $this->actingAs($this->teammate)->patchJson(route('comments.update', $comment), ['body' => 'edited'])->assertForbidden();
    $this->actingAs($this->agent)->patchJson(route('comments.update', $comment), ['body' => 'edited'])->assertOk()->assertJsonPath('comment.body', 'edited');

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->postJson(route('conversations.comments.store', $this->conversation), ['body' => 'nope'])->assertForbidden();
});
