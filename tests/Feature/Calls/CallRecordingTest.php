<?php

use App\Enums\Calls\ParticipantStatus;
use App\Enums\Calls\RecordingStatus;
use App\Events\Calls\Broadcast\CallUpdated;
use App\Models\Calls\CallParticipant;
use App\Models\Calls\CallRecording;
use App\Notifications\Calls\CallRecordingNotification;
use App\Services\Calls\CallRecordings;
use App\Services\Mail\Providers\Forwarding\WebhookSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CallsTestHelpers;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');

    $w = CallsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->outsider = $w['outsider'];
    $this->call = $w['call'];
    $this->host = $w['host'];
    $this->call->update(['status' => 'live', 'started_at' => now()->subMinute()]);

    /** Deliver a recorder webhook the way the recorder signs it. */
    $this->recorderEvent = function (array $payload, ?string $secret = CallsTestHelpers::RECORDER_SECRET) {
        $body = (string) json_encode($payload);

        return $this->call('POST', route('webhooks.calls.recorder'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TANDEM_SIGNATURE' => app(WebhookSignature::class)->sign((string) $secret, $body),
        ], $body);
    };
});

test('a host starts a recording through the recorder and everyone is told', function () {
    $uuid = $this->actingAs($this->admin)
        ->postJson(route('meet.recordings.store', $this->call->code), ['mode' => 'screen'])
        ->assertCreated()
        ->assertJsonPath('recording.status', 'starting')
        ->assertJsonPath('recording.mode', 'screen')
        ->json('recording.uuid');

    $recording = CallRecording::query()->where('uuid', $uuid)->sole();

    expect($recording->path)->toBe($this->call->uuid.'/'.$uuid)
        ->and($this->host->fresh()->recording_consent_at)->not->toBeNull();

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://recorder.test/recordings'
        && $request->hasHeader('Authorization', 'Bearer '.CallsTestHelpers::RECORDER_SECRET)
        && $request['id'] === $uuid
        && $request['room'] === $this->call->roomName()
        && $request['mode'] === 'screen'
        && $request['path'] === $recording->path);

    Event::assertDispatched(CallUpdated::class, fn (CallUpdated $event) => $event->reason === 'recording.starting');
    $this->assertDatabaseHas('audit_logs', ['action' => 'call.recording_started', 'user_id' => $this->admin->id]);
});

test('guests can never record and members only when the host allows it', function () {
    $guest = CallParticipant::factory()->create(['call_id' => $this->call->id]);
    CallsTestHelpers::memberIn($this->call, $this->member);

    $this->withSession(CallsTestHelpers::guestSession($guest))->postJson(route('meet.recordings.store', $this->call->code))->assertForbidden();
    $this->actingAs($this->member)->postJson(route('meet.recordings.store', $this->call->code))->assertForbidden();

    $this->call->update(['settings' => ['recording' => 'members']]);

    $this->actingAs($this->member)->postJson(route('meet.recordings.store', $this->call->code))->assertCreated();
    expect(CallRecording::count())->toBe(1);
});

test('a second recording cannot start while one is running', function () {
    CallRecording::factory()->create(['call_id' => $this->call->id]);

    $this->actingAs($this->admin)->postJson(route('meet.recordings.store', $this->call->code))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recording' => 'This meeting is already being recorded.']);
});

test('a recorder that cannot be reached fails the start loudly instead of pretending to record', function () {
    config(['calls.recorder.url' => 'http://recorder-down.test']);
    Http::fake(['recorder-down.test/*' => fn () => throw new ConnectionException('refused')]);

    $this->actingAs($this->admin)->postJson(route('meet.recordings.store', $this->call->code))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recording' => 'The recorder could not be reached.']);

    expect(CallRecording::query()->sole()->status)->toBe(RecordingStatus::Failed);
});

test('the recorder reports low disk space as a refusal the host can read', function () {
    config(['calls.recorder.url' => 'http://recorder-full.test']);
    Http::fake(['recorder-full.test/*' => Http::response(['error' => 'disk'], 507)]);

    $this->actingAs($this->admin)->postJson(route('meet.recordings.store', $this->call->code))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recording' => 'There is not enough free disk space to record.']);
});

test('stopping asks the recorder and leaves the recording processing until it reports back', function () {
    $recording = CallRecording::factory()->create(['call_id' => $this->call->id, 'started_by_id' => $this->host->id]);

    $this->actingAs($this->admin)
        ->postJson(route('meet.recordings.stop', [$this->call->code, $recording->uuid]))
        ->assertOk()
        ->assertJsonPath('recording.status', 'processing');

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://recorder.test/recordings/'.$recording->uuid.'/stop');
});

test('the recording keeps running when the person who started it leaves', function () {
    $recording = CallRecording::factory()->create(['call_id' => $this->call->id, 'started_by_id' => $this->host->id]);
    $this->host->update(['status' => ParticipantStatus::Joined]);

    $this->actingAs($this->admin)->postJson(route('meet.leave', $this->call->code))->assertOk();

    expect($recording->fresh()->status)->toBe(RecordingStatus::Recording);
    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/stop'));
});

test('ending the meeting stops its recording', function () {
    $recording = CallRecording::factory()->create(['call_id' => $this->call->id]);

    $this->actingAs($this->admin)->postJson(route('meet.end', $this->call->code))->assertOk();

    expect($recording->fresh()->status)->toBe(RecordingStatus::Processing);
});

test('recorder webhooks with a bad signature return 401 and change nothing', function () {
    $recording = CallRecording::factory()->create(['call_id' => $this->call->id, 'status' => RecordingStatus::Starting]);

    ($this->recorderEvent)(['event' => 'recording.started', 'id' => $recording->uuid], 'wrong-secret')->assertUnauthorized();

    expect($recording->fresh()->status)->toBe(RecordingStatus::Starting);
});

test('the recorder confirms the start, reports progress and the finished manifest makes it ready', function () {
    $this->organization->update(['call_settings' => ['recording_retention_days' => 30]]);
    $recording = CallRecording::factory()->create(['call_id' => $this->call->id, 'status' => RecordingStatus::Starting, 'started_by_id' => $this->host->id]);

    ($this->recorderEvent)(['event' => 'recording.started', 'id' => $recording->uuid, 'started_at' => '2026-11-12T10:00:00Z'])->assertOk();
    expect($recording->fresh()->status)->toBe(RecordingStatus::Recording);

    ($this->recorderEvent)(['event' => 'recording.progress', 'id' => $recording->uuid, 'duration_ms' => 15000, 'size_bytes' => 4096])->assertOk();
    expect($recording->fresh()->duration_ms)->toBe(15000);

    $manifest = CallRecording::factory()->ready()->make()->manifest;
    ($this->recorderEvent)(['event' => 'recording.finished', 'id' => $recording->uuid, 'manifest' => [...$manifest, 'ended_at' => '2026-11-12T10:01:00Z']])->assertOk();

    $recording->refresh();

    expect($recording->status)->toBe(RecordingStatus::Ready)
        ->and($recording->duration_ms)->toBe(60000)
        ->and($recording->tracks())->toHaveCount(2)
        ->and($recording->expires_at->toDateString())->toBe('2026-12-12');

    Notification::assertSentTo($this->admin, CallRecordingNotification::class, fn (CallRecordingNotification $notification) => $notification->outcome === 'ready');
    Event::assertDispatched(CallUpdated::class, fn (CallUpdated $event) => $event->reason === 'recording.finished');
});

test('a failure is stored, broadcast and sent to the host; salvaged tracks stay playable', function () {
    $recording = CallRecording::factory()->create(['call_id' => $this->call->id, 'started_by_id' => $this->host->id]);
    $manifest = [...CallRecording::factory()->ready()->make()->manifest, 'status' => 'partial'];

    ($this->recorderEvent)(['event' => 'recording.failed', 'id' => $recording->uuid, 'reason' => 'storage interrupted', 'manifest' => $manifest])->assertOk();

    $recording->refresh();

    expect($recording->status)->toBe(RecordingStatus::Partial)
        ->and($recording->failure_reason)->toBe('storage interrupted');

    Notification::assertSentTo($this->admin, CallRecordingNotification::class, fn (CallRecordingNotification $notification) => $notification->outcome === 'partial');
    Event::assertDispatched(CallUpdated::class, fn (CallUpdated $event) => $event->reason === 'recording.failed' && $event->extra['message'] === 'storage interrupted');
    $this->assertDatabaseHas('audit_logs', ['action' => 'call.recording_failed', 'organization_id' => $this->organization->id]);
});

test('a failure with nothing salvaged is marked failed', function () {
    $recording = CallRecording::factory()->create(['call_id' => $this->call->id]);

    ($this->recorderEvent)(['event' => 'recording.failed', 'id' => $recording->uuid, 'reason' => 'could not join the room'])->assertOk();

    expect($recording->fresh()->status)->toBe(RecordingStatus::Failed);
    Notification::assertSentTo($this->admin, CallRecordingNotification::class, fn (CallRecordingNotification $notification) => $notification->outcome === 'failed');
});

test('a warning is kept for the host and cleared when the recorder recovers', function () {
    $recording = CallRecording::factory()->create(['call_id' => $this->call->id]);

    ($this->recorderEvent)(['event' => 'recording.warning', 'id' => $recording->uuid, 'code' => 'disk_low', 'message' => 'Disk space is running low.'])->assertOk();
    expect($recording->fresh()->last_warning)->toBe('Disk space is running low.');

    ($this->recorderEvent)(['event' => 'recording.warning', 'id' => $recording->uuid, 'code' => 'recovered', 'message' => 'Recording resumed.'])->assertOk();
    expect($recording->fresh()->last_warning)->toBeNull();
});

test('a recording that stops reporting is flagged, then failed when the recorder never comes back', function () {
    $recording = CallRecording::factory()->create(['call_id' => $this->call->id, 'heartbeat_at' => now()->subMinutes(3)]);
    config(['calls.recorder.url' => 'http://recorder-gone.test']);
    Http::fake(['recorder-gone.test/*' => Http::response([], 404)]);

    app(CallRecordings::class)->checkStalled();

    expect($recording->fresh()->status)->toBe(RecordingStatus::Recording)
        ->and($recording->fresh()->last_warning)->toContain('not responding');

    $recording->update(['heartbeat_at' => now()->subMinutes(20)]);
    app(CallRecordings::class)->checkStalled();

    expect($recording->fresh()->status)->toBe(RecordingStatus::Failed);
    Notification::assertSentTo($this->admin, CallRecordingNotification::class);
});

test('a lost finished webhook is recovered from the recorder', function () {
    $recording = CallRecording::factory()->create(['call_id' => $this->call->id, 'status' => RecordingStatus::Processing, 'heartbeat_at' => now()->subMinutes(3)]);
    config(['calls.recorder.url' => 'http://recorder-done.test']);
    Http::fake(['recorder-done.test/*' => Http::response(['id' => $recording->uuid, 'status' => 'complete', 'manifest' => CallRecording::factory()->ready()->make()->manifest])]);

    app(CallRecordings::class)->checkStalled();

    expect($recording->fresh()->status)->toBe(RecordingStatus::Ready);
});

test('participants acknowledge a recording once and it is audited', function () {
    $guest = CallParticipant::factory()->create(['call_id' => $this->call->id, 'name' => 'Grace Guest']);

    $this->withSession(CallsTestHelpers::guestSession($guest))->postJson(route('meet.recordings.consent', $this->call->code))->assertOk();
    $this->withSession(CallsTestHelpers::guestSession($guest))->postJson(route('meet.recordings.consent', $this->call->code))->assertOk();

    expect($guest->fresh()->recording_consent_at)->not->toBeNull();
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'call.recording_consent']);
});

test('expired recordings are pruned with their files', function () {
    $expired = CallRecording::factory()->ready()->create(['call_id' => $this->call->id, 'expires_at' => now()->subDay()]);
    $kept = CallRecording::factory()->ready()->create(['call_id' => $this->call->id, 'expires_at' => now()->addDay()]);
    $directory = app(CallRecordings::class)->directory($expired);
    Storage::disk('local')->put($directory.'/0001-microphone-p_a.webm', 'audio');

    $this->artisan('calls:prune-recordings')->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($kept);
    Storage::disk('local')->assertMissing($directory.'/0001-microphone-p_a.webm');
});

test('the recorder and this application agree on the webhook signature', function () {
    // The same vector is pinned in media/tandem-media/internal/signature/signature_test.go.
    expect(app(WebhookSignature::class)->sign('test-secret', '{"hello":"world"}', 1700000000))
        ->toBe('t=1700000000,v1=f85455a1b55ea86f7a15f5f9923d0abc4b888da84ec485f2ff358f427776beca');
});
