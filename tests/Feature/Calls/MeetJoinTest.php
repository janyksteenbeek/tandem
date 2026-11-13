<?php

use App\Enums\Calls\ParticipantRole;
use App\Enums\Calls\ParticipantStatus;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Models\Organization;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CallsTestHelpers;

beforeEach(function () {
    $w = CallsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->outsider = $w['outsider'];
    $this->call = $w['call'];
    $this->host = $w['host'];
});

test('the meeting link renders for a visitor without an account and reveals nothing beyond the invitation', function () {
    $this->get(route('meet.show', $this->call->code))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('meet/Room')
            ->where('call.title', 'Weekly sync')
            ->where('call.uuid', null)
            ->where('call.history_url', null)
            ->where('viewer.is_signed_in', false)
            ->where('viewer.role', 'guest')
            ->where('viewer.status', null));
});

test('an unknown meeting code returns 404', function () {
    $this->get('/meet/zzz-zzzz-zzz')->assertNotFound();
});

test('an organization member walks straight in', function () {
    $this->actingAs($this->member)
        ->postJson(route('meet.join', $this->call->code))
        ->assertOk()
        ->assertJsonPath('status', 'admitted')
        ->assertJsonPath('call_uuid', $this->call->uuid);

    $this->assertDatabaseHas('call_participants', [
        'call_id' => $this->call->id,
        'user_id' => $this->member->id,
        'role' => ParticipantRole::Member->value,
        'status' => ParticipantStatus::Admitted->value,
    ]);
});

test('a guest waits for a host when the waiting room is on and is remembered by the session', function () {
    $response = $this->postJson(route('meet.join', $this->call->code), ['name' => 'Grace Guest'])
        ->assertOk()
        ->assertJsonPath('status', 'waiting')
        ->assertJsonPath('call_uuid', null);

    $guest = CallParticipant::query()->where('name', 'Grace Guest')->sole();

    expect($guest->role)->toBe(ParticipantRole::Guest)->and($guest->user_id)->toBeNull();
    $response->assertSessionHas('calls.guest.'.$this->call->id, $guest->id);

    $this->getJson(route('meet.status', $this->call->code))->assertOk()->assertJsonPath('status', 'waiting');
});

test('a guest is admitted directly when the waiting room is off', function () {
    $this->call->update(['settings' => ['waiting_room' => false]]);

    $this->postJson(route('meet.join', $this->call->code), ['name' => 'Grace Guest'])
        ->assertOk()
        ->assertJsonPath('status', 'admitted');
});

test('a signed-in user from another organization is treated as a guest', function () {
    $this->actingAs($this->outsider)
        ->postJson(route('meet.join', $this->call->code))
        ->assertOk()
        ->assertJsonPath('status', 'waiting')
        ->assertJsonPath('participant.role', 'guest');
});

test('a guest needs a name', function () {
    $this->postJson(route('meet.join', $this->call->code), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'The name field is required.']);
});

test('a guest is refused with 422 on a wrong password and let through with the right one', function () {
    $this->call->update(['password' => 'opensesame', 'settings' => ['waiting_room' => false]]);

    $this->postJson(route('meet.join', $this->call->code), ['name' => 'Grace', 'password' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'password');

    $this->postJson(route('meet.join', $this->call->code), ['name' => 'Grace', 'password' => 'opensesame'])
        ->assertOk()
        ->assertJsonPath('status', 'admitted');
});

test('members are not asked for the meeting password', function () {
    $this->call->update(['password' => 'opensesame']);

    $this->actingAs($this->member)->postJson(route('meet.join', $this->call->code))->assertOk()->assertJsonPath('status', 'admitted');
});

test('a locked meeting returns 403 to everyone but the host', function () {
    $this->call->update(['settings' => ['locked' => true]]);

    $this->actingAs($this->member)
        ->postJson(route('meet.join', $this->call->code))
        ->assertForbidden()
        ->assertJsonPath('reason', 'locked');

    $this->assertDatabaseMissing('call_participants', ['call_id' => $this->call->id, 'user_id' => $this->member->id]);
});

test('an ended meeting returns 403 with the ended reason', function () {
    $ended = Call::factory()->ended()->create(['organization_id' => $this->organization->id, 'host_id' => $this->admin->id]);

    $this->postJson(route('meet.join', $ended->code), ['name' => 'Grace'])->assertForbidden()->assertJsonPath('reason', 'ended');
});

test('someone a host removed cannot come back through the link', function () {
    $guest = CallParticipant::factory()->create(['call_id' => $this->call->id, 'status' => ParticipantStatus::Removed]);

    $this->withSession(CallsTestHelpers::guestSession($guest))
        ->postJson(route('meet.join', $this->call->code), ['name' => 'Back again'])
        ->assertForbidden()
        ->assertJsonPath('reason', 'removed');
});

test('a personal invitation skips the waiting room and the password and can be used once', function () {
    $this->call->update(['password' => 'opensesame']);
    $invited = CallParticipant::factory()->create([
        'call_id' => $this->call->id,
        'email' => 'grace@example.com',
        'status' => ParticipantStatus::Invited,
        'admitted_at' => null,
        'invite_token' => hash('sha256', 'the-token'),
    ]);

    $this->postJson(route('meet.join', $this->call->code), ['name' => 'Grace', 'invite' => 'the-token'])
        ->assertOk()
        ->assertJsonPath('status', 'admitted')
        ->assertJsonPath('participant.uuid', $invited->uuid);

    expect($invited->fresh()->invite_token)->toBeNull();

    // A second browser presenting the same token is an ordinary guest again.
    $this->flushSession();
    $this->postJson(route('meet.join', $this->call->code), ['name' => 'Mallory', 'invite' => 'the-token'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'password');
});

test('media credentials are scoped to the room and to what the participant may publish', function () {
    $this->call->update(['settings' => ['screen_share' => 'hosts']]);
    CallsTestHelpers::memberIn($this->call, $this->member);

    $token = $this->actingAs($this->member)
        ->postJson(route('meet.credentials', $this->call->code))
        ->assertOk()
        ->assertJsonPath('url', 'wss://meet.test')
        ->json('token');

    $claims = CallsTestHelpers::claims($token);

    expect($claims['video']['room'])->toBe($this->call->roomName())
        ->and($claims['video']['roomJoin'])->toBeTrue()
        ->and($claims['video']['canPublishSources'])->toBe(['camera', 'microphone'])
        ->and($claims['video'])->not->toHaveKey('roomAdmin')
        ->and($claims['exp'] - $claims['iat'])->toBe(600)
        ->and($claims['name'])->toBe($this->member->name);

    $hostToken = $this->actingAs($this->admin)->postJson(route('meet.credentials', $this->call->code))->json('token');

    expect(CallsTestHelpers::claims($hostToken)['video']['canPublishSources'])->toContain('screen_share');

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/twirp/livekit.RoomService/CreateRoom') && $request['name'] === $this->call->roomName());
});

test('credentials are refused with 403 to someone still in the waiting room', function () {
    $guest = CallParticipant::factory()->waiting()->create(['call_id' => $this->call->id]);

    $this->withSession(CallsTestHelpers::guestSession($guest))
        ->postJson(route('meet.credentials', $this->call->code))
        ->assertForbidden();
});

test('a guest session for one call opens nothing in another call', function () {
    $guest = CallParticipant::factory()->create(['call_id' => $this->call->id]);
    $other = Call::factory()->create(['organization_id' => Organization::factory()]);
    CallParticipant::factory()->create(['call_id' => $other->id]);

    $this->withSession(CallsTestHelpers::guestSession($guest))
        ->getJson(route('meet.state', $other->code))
        ->assertForbidden();
});

test('a full meeting refuses new connections with 403 but lets a connected participant refresh', function () {
    config(['calls.max_participants' => 1]);
    $this->host->update(['status' => ParticipantStatus::Joined, 'joined_at' => now()]);
    CallsTestHelpers::memberIn($this->call, $this->member);

    $this->actingAs($this->member)->postJson(route('meet.credentials', $this->call->code))->assertForbidden()->assertJsonPath('reason', 'full');
    $this->actingAs($this->admin)->postJson(route('meet.credentials', $this->call->code))->assertOk();
});

test('connecting starts the call and the room state lists who is inside', function () {
    CallParticipant::factory()->waiting()->create(['call_id' => $this->call->id, 'name' => 'Waiting Wendy']);

    $this->actingAs($this->admin)->postJson(route('meet.connected', $this->call->code))->assertOk();

    expect($this->call->fresh()->status->value)->toBe('live')
        ->and($this->call->fresh()->started_at)->not->toBeNull()
        ->and($this->host->fresh()->status)->toBe(ParticipantStatus::Joined);

    $this->actingAs($this->admin)->getJson(route('meet.state', $this->call->code))
        ->assertOk()
        ->assertJsonPath('me.can.manage', true)
        ->assertJsonPath('me.can.record', true)
        ->assertJsonCount(2, 'participants')
        ->assertJsonMissingPath('participants.0.email');
});

test('only hosts see who is in the waiting room', function () {
    CallParticipant::factory()->waiting()->create(['call_id' => $this->call->id, 'name' => 'Waiting Wendy']);
    CallsTestHelpers::memberIn($this->call, $this->member);

    $names = $this->actingAs($this->member)->getJson(route('meet.state', $this->call->code))->assertOk()->json('participants.*.name');

    expect($names)->not->toContain('Waiting Wendy');
});
