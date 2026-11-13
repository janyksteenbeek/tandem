<?php

use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use Tests\Support\CallsTestHelpers;

/**
 * The call's realtime channel carries chat and participant updates, so its authorization is
 * the same boundary as the HTTP endpoints: only people who were let into this call.
 */
beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    $w = CallsTestHelpers::workspace();
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->call = $w['call'];

    require base_path('routes/channels.php');

    $this->authorize = fn (Call $call) => $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-call.'.$call->uuid]);
});

test('a signed-in participant may listen to their call', function () {
    $this->actingAs($this->admin);

    ($this->authorize)($this->call)->assertOk()->assertJsonStructure(['auth']);
});

test('a member who has not joined the call is refused', function () {
    $this->actingAs($this->member);

    ($this->authorize)($this->call)->assertForbidden();
});

test('an admitted guest may listen through their session; a waiting one may not', function () {
    $guest = CallParticipant::factory()->create(['call_id' => $this->call->id]);
    $this->withSession(CallsTestHelpers::guestSession($guest));

    ($this->authorize)($this->call)->assertOk()->assertJsonStructure(['auth']);

    $guest->update(['status' => 'waiting']);

    ($this->authorize)($this->call)->assertForbidden();
});

test('a guest session never authorizes another call or any other channel', function () {
    $guest = CallParticipant::factory()->create(['call_id' => $this->call->id]);
    $other = Call::factory()->create();
    CallParticipant::factory()->create(['call_id' => $other->id]);
    $this->withSession(CallsTestHelpers::guestSession($guest));

    ($this->authorize)($other)->assertForbidden();

    $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-user.'.$this->admin->id])->assertForbidden();
});

test('a visitor with no session at all is refused', function () {
    ($this->authorize)($this->call)->assertForbidden();
});
