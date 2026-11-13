<?php

use App\Enums\Calls\ParticipantStatus;
use App\Jobs\Calls\SendCallMail;
use App\Mail\Calls\CallInvitationMail;
use App\Models\Calls\CallParticipant;
use App\Notifications\Calls\CallInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CallsTestHelpers;

beforeEach(function () {
    Notification::fake();
    Queue::fake();

    $w = CallsTestHelpers::workspace();
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->call = $w['call'];
});

test('inviting a teammate notifies them and an outside address gets a personal link by email', function () {
    $this->actingAs($this->admin)
        ->postJson(route('meet.invitations.store', $this->call->code), ['user_ids' => [$this->member->id], 'emails' => ['Client@Example.org'], 'note' => 'Quick sync'])
        ->assertOk()
        ->assertJsonPath('invited', 2);

    Notification::assertSentTo($this->member, CallInvitationNotification::class);

    $external = CallParticipant::query()->where('email', 'client@example.org')->sole();

    expect($external->status)->toBe(ParticipantStatus::Invited)
        ->and($external->invite_token)->toHaveLength(64)
        ->and($external->user_id)->toBeNull();

    Queue::assertPushed(SendCallMail::class, fn (SendCallMail $job) => $job->email === 'client@example.org'
        && $job->mailable instanceof CallInvitationMail
        && str_contains($job->mailable->joinUrl, '?invite=')
        && ! str_contains($job->mailable->joinUrl, (string) $external->invite_token));

    // The teammate's link carries no token: they are recognised by signing in.
    Queue::assertPushed(SendCallMail::class, fn (SendCallMail $job) => $job->email === $this->member->email && ! str_contains($job->mailable->joinUrl, 'invite='));
});

test('an address that belongs to a member is invited as that member, not as a guest', function () {
    $this->actingAs($this->admin)
        ->postJson(route('meet.invitations.store', $this->call->code), ['emails' => [$this->member->email]])
        ->assertOk();

    $this->assertDatabaseHas('call_participants', ['call_id' => $this->call->id, 'user_id' => $this->member->id, 'role' => 'member', 'status' => 'invited']);
    $this->assertDatabaseMissing('call_participants', ['call_id' => $this->call->id, 'user_id' => null, 'email' => $this->member->email]);
});

test('guests cannot send invitations', function () {
    $guest = CallParticipant::factory()->create(['call_id' => $this->call->id]);

    $this->withSession(CallsTestHelpers::guestSession($guest))
        ->postJson(route('meet.invitations.store', $this->call->code), ['emails' => ['x@example.org']])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('an invitation needs at least one valid recipient', function () {
    $this->actingAs($this->admin)->postJson(route('meet.invitations.store', $this->call->code), [])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson(route('meet.invitations.store', $this->call->code), ['emails' => ['nope']])->assertUnprocessable()->assertJsonValidationErrors(['emails.0']);
});

test('the invitation email offers the join link and mentions a password without revealing it', function () {
    $this->call->update(['password' => 'opensesame']);

    $mail = new CallInvitationMail($this->call->fresh(), $this->admin, 'https://tandem.test/meet/abc-defg-hij?invite=tok', 'Quick sync');

    $mail->assertSeeInHtml($this->admin->name.' invited you to a meeting');
    $mail->assertDontSeeInHtml($this->admin->email);
    $mail->assertSeeInHtml('https://tandem.test/meet/abc-defg-hij?invite=tok', false);
    $mail->assertSeeInHtml('Quick sync');
    $mail->assertSeeInHtml('This meeting has a password');
    $mail->assertDontSeeInHtml('opensesame');
});
