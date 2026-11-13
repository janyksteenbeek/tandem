<?php

use App\Enums\Calls\ParticipantStatus;
use App\Models\Calls\CallParticipant;
use App\Services\Calls\Media\AccessToken;
use Illuminate\Support\Facades\Http;
use Tests\Support\CallsTestHelpers;

beforeEach(function () {
    $w = CallsTestHelpers::workspace();
    $this->call = $w['call'];
    $this->host = $w['host'];

    /** Deliver a room event signed the way the media server signs it. */
    $this->mediaEvent = function (array $event, ?string $token = null) {
        $body = (string) json_encode($event);
        $token ??= app(AccessToken::class)->sign('', [], 300, ['sha256' => base64_encode(hash('sha256', $body, true))]);

        return $this->call('POST', route('webhooks.calls.media'), [], [], [], [
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => $token,
        ], $body);
    };
});

test('a participant joining starts the call and leaving marks them as left', function () {
    $event = ['room' => ['name' => $this->call->roomName()], 'participant' => ['identity' => $this->host->identity()]];

    ($this->mediaEvent)(['event' => 'participant_joined', ...$event])->assertOk();

    expect($this->host->fresh()->status)->toBe(ParticipantStatus::Joined)
        ->and($this->call->fresh()->isLive())->toBeTrue()
        ->and($this->call->fresh()->peak_participants)->toBe(1);

    ($this->mediaEvent)(['event' => 'participant_left', ...$event])->assertOk();

    expect($this->host->fresh()->status)->toBe(ParticipantStatus::Left)
        ->and($this->host->fresh()->left_at)->not->toBeNull();
});

test('the room closing ends the call', function () {
    $this->call->update(['status' => 'live', 'started_at' => now()->subMinutes(5)]);

    ($this->mediaEvent)(['event' => 'room_finished', 'room' => ['name' => $this->call->roomName()]])->assertOk();

    expect($this->call->fresh()->hasEnded())->toBeTrue();
});

test('the hidden recorder and unknown rooms are ignored', function () {
    ($this->mediaEvent)(['event' => 'participant_joined', 'room' => ['name' => $this->call->roomName()], 'participant' => ['identity' => 'recorder_abc']])->assertOk();
    ($this->mediaEvent)(['event' => 'room_finished', 'room' => ['name' => 'someone-elses-room']])->assertStatus(202);

    expect($this->call->fresh()->status->value)->toBe('pending');
});

test('someone who was never admitted is not marked as connected', function () {
    $waiting = CallParticipant::factory()->waiting()->create(['call_id' => $this->call->id]);

    ($this->mediaEvent)(['event' => 'participant_joined', 'room' => ['name' => $this->call->roomName()], 'participant' => ['identity' => $waiting->identity()]])->assertOk();

    expect($waiting->fresh()->status)->toBe(ParticipantStatus::Waiting);
});

test('a webhook returns 401 when the token is missing, signed with another key, or does not match the body', function (Closure $token) {
    $event = ['event' => 'room_finished', 'room' => ['name' => $this->call->roomName()]];

    ($this->mediaEvent)($event, $token())->assertUnauthorized();

    expect($this->call->fresh()->hasEnded())->toBeFalse();
})->with([
    'no token' => [fn () => ''],
    'foreign key' => [fn () => (new AccessToken('testkey', 'another-secret-another-secret-another'))->sign('', [], 300, ['sha256' => 'x'])],
    'body hash mismatch' => [fn () => app(AccessToken::class)->sign('', [], 300, ['sha256' => base64_encode(hash('sha256', '{"event":"other"}', true))])],
]);

test('the sync command ends live calls whose room has been gone for a while and corrects presence', function () {
    $this->call->update(['status' => 'live', 'started_at' => now()->subHour()]);
    $this->host->update(['status' => ParticipantStatus::Joined]);
    CallParticipant::query()->update(['updated_at' => now()->subMinutes(10)]);
    CallsTestHelpers::$mediaResponses['ListRooms'] = ['rooms' => []];

    $this->artisan('calls:sync')->assertSuccessful();

    expect($this->call->fresh()->hasEnded())->toBeTrue();
});

test('the sync command leaves a call alone while its room exists and marks dropped participants as left', function () {
    $this->call->update(['status' => 'live', 'started_at' => now()->subHour()]);
    $this->host->update(['status' => ParticipantStatus::Joined]);
    $guest = CallParticipant::factory()->create(['call_id' => $this->call->id]);
    CallsTestHelpers::$mediaResponses['ListRooms'] = ['rooms' => [['name' => $this->call->roomName()]]];
    CallsTestHelpers::$mediaResponses['ListParticipants'] = ['participants' => [['identity' => $guest->identity()]]];

    $this->artisan('calls:sync')->assertSuccessful();

    expect($this->call->fresh()->isLive())->toBeTrue()
        ->and($this->host->fresh()->status)->toBe(ParticipantStatus::Left)
        ->and($guest->fresh()->status)->toBe(ParticipantStatus::Joined);

    Http::assertSentCount(2);
});
