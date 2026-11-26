<?php

use App\Enums\Calls\ParticipantStatus;
use App\Events\Calls\Broadcast\CallParticipantUpdated;
use App\Events\Calls\Broadcast\CallUpdated;
use App\Models\Calls\CallParticipant;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\CallsTestHelpers;

beforeEach(function () {
    $w = CallsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->call = $w['call'];
    $this->host = $w['host'];
    $this->guest = CallParticipant::factory()->waiting()->create(['call_id' => $this->call->id, 'name' => 'Grace Guest']);
});

test('a host admits a waiting guest and the room is told', function () {
    $this->actingAs($this->admin)
        ->postJson(route('meet.participants.admit', [$this->call->code, $this->guest->uuid]))
        ->assertOk()
        ->assertJsonPath('participant.status', 'admitted');

    expect($this->guest->fresh()->status)->toBe(ParticipantStatus::Admitted);
    Event::assertDispatched(CallParticipantUpdated::class, fn (CallParticipantUpdated $event) => $event->participant->is($this->guest));
});

test('a host denies a waiting guest, who then gets no credentials', function () {
    $this->actingAs($this->admin)->postJson(route('meet.participants.deny', [$this->call->code, $this->guest->uuid]))->assertOk();

    expect($this->guest->fresh()->status)->toBe(ParticipantStatus::Denied);

    auth()->logout();
    $this->withSession(CallsTestHelpers::guestSession($this->guest))
        ->postJson(route('meet.credentials', $this->call->code))
        ->assertForbidden();
});

test('host controls return 403 to a member who is not a host', function (string $route) {
    CallsTestHelpers::memberIn($this->call, $this->member);

    $this->actingAs($this->member)->postJson(route($route, [$this->call->code, $this->guest->uuid]))->assertForbidden();

    expect($this->guest->fresh()->status)->toBe(ParticipantStatus::Waiting);
})->with(['meet.participants.admit', 'meet.participants.deny', 'meet.participants.remove', 'meet.participants.mute']);

test('ending and changing settings return 403 to a member who is not a host', function () {
    CallsTestHelpers::memberIn($this->call, $this->member);

    $this->actingAs($this->member)->postJson(route('meet.end', $this->call->code))->assertForbidden();
    $this->actingAs($this->member)->patchJson(route('meet.settings', $this->call->code), ['locked' => true])->assertForbidden();

    expect($this->call->fresh()->hasEnded())->toBeFalse();
});

test('a participant of another call cannot be reached through this call', function () {
    $elsewhere = CallParticipant::factory()->waiting()->create();

    $this->actingAs($this->admin)
        ->postJson(route('meet.participants.admit', [$this->call->code, $elsewhere->uuid]))
        ->assertNotFound();
});

test('removing a participant disconnects them from the media server and is audited', function () {
    $this->guest->update(['status' => ParticipantStatus::Joined]);

    $this->actingAs($this->admin)
        ->postJson(route('meet.participants.remove', [$this->call->code, $this->guest->uuid]))
        ->assertOk()
        ->assertJsonPath('participant.status', 'removed');

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/RemoveParticipant')
        && $request['room'] === $this->call->roomName()
        && $request['identity'] === $this->guest->identity());

    $this->assertDatabaseHas('audit_logs', ['action' => 'call.participant_removed', 'organization_id' => $this->organization->id, 'user_id' => $this->admin->id]);
});

test('a host cannot remove another host or themselves', function () {
    $this->actingAs($this->admin)
        ->postJson(route('meet.participants.remove', [$this->call->code, $this->host->uuid]))
        ->assertUnprocessable();
});

test('muting switches off only the microphone tracks that are live', function () {
    CallsTestHelpers::$mediaResponses['ListParticipants'] = ['participants' => [[
        'identity' => $this->guest->identity(),
        'tracks' => [
            ['sid' => 'TR_mic', 'source' => 'MICROPHONE', 'muted' => false],
            ['sid' => 'TR_cam', 'source' => 'CAMERA', 'muted' => false],
        ],
    ]]];
    $this->guest->update(['status' => ParticipantStatus::Joined]);

    $this->actingAs($this->admin)
        ->postJson(route('meet.participants.mute', [$this->call->code, $this->guest->uuid]))
        ->assertOk()
        ->assertJsonPath('muted', true);

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/MutePublishedTrack') && $request['track_sid'] === 'TR_mic' && $request['muted'] === true);
    Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/MutePublishedTrack') && $request['track_sid'] === 'TR_cam');
});

test('a host changes settings, sets a password and connected participants lose screen sharing', function () {
    $member = CallsTestHelpers::memberIn($this->call, $this->member);
    $member->update(['status' => ParticipantStatus::Joined]);

    $this->actingAs($this->admin)
        ->patchJson(route('meet.settings', $this->call->code), ['locked' => true, 'screen_share' => 'hosts', 'password' => 'opensesame', 'title' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('call.settings.locked', true)
        ->assertJsonPath('call.settings.screen_share', 'hosts')
        ->assertJsonPath('call.has_password', true)
        ->assertJsonPath('call.title', 'Renamed');

    expect(Hash::check('opensesame', $this->call->fresh()->password))->toBeTrue();

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/UpdateParticipant')
        && $request['identity'] === $member->identity()
        && $request['permission']['can_publish_sources'] === ['CAMERA', 'MICROPHONE']);

    Event::assertDispatched(CallUpdated::class, fn (CallUpdated $event) => $event->reason === 'settings');
});

test('switching off the waiting room lets in whoever is waiting and everyone after them', function () {
    $this->actingAs($this->admin)
        ->patchJson(route('meet.settings', $this->call->code), ['waiting_room' => false])
        ->assertOk()
        ->assertJsonPath('call.settings.waiting_room', false);

    expect($this->guest->fresh()->status)->toBe(ParticipantStatus::Admitted)
        ->and($this->guest->fresh()->admitted_at)->not->toBeNull();

    Event::assertDispatched(CallParticipantUpdated::class, fn (CallParticipantUpdated $event) => $event->participant->is($this->guest));

    auth()->logout();
    $this->postJson(route('meet.join', $this->call->code), ['name' => 'Late Larry'])
        ->assertOk()
        ->assertJsonPath('status', 'admitted');
});

test('switching off the waiting room of a locked meeting keeps waiting guests out', function () {
    $this->call->update(['settings' => ['locked' => true]]);

    $this->actingAs($this->admin)
        ->patchJson(route('meet.settings', $this->call->code), ['waiting_room' => false])
        ->assertOk();

    expect($this->guest->fresh()->status)->toBe(ParticipantStatus::Waiting);
});

test('settings reject values outside the allowed set', function () {
    $this->actingAs($this->admin)
        ->patchJson(route('meet.settings', $this->call->code), ['screen_share' => 'nobody', 'password' => 'abc'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['screen_share', 'password']);
});

test('ending the meeting closes the room, marks everyone as left and turns waiting guests away', function () {
    $this->call->update(['status' => 'live', 'started_at' => now()->subMinutes(10)]);
    $this->host->update(['status' => ParticipantStatus::Joined, 'joined_at' => now()->subMinutes(10)]);

    $this->actingAs($this->admin)->postJson(route('meet.end', $this->call->code))->assertOk();

    $call = $this->call->fresh();

    expect($call->hasEnded())->toBeTrue()
        ->and($call->ended_at)->not->toBeNull()
        ->and($this->host->fresh()->status)->toBe(ParticipantStatus::Left)
        ->and($this->guest->fresh()->status)->toBe(ParticipantStatus::Denied);

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/DeleteRoom') && $request['room'] === $this->call->roomName());
    Event::assertDispatched(CallUpdated::class, fn (CallUpdated $event) => $event->reason === 'ended');
});
