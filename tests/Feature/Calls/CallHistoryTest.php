<?php

use App\Enums\Calls\ExportStatus;
use App\Jobs\Calls\BuildRecordingExport;
use App\Models\Calls\Call;
use App\Models\Calls\CallMessage;
use App\Models\Calls\CallParticipant;
use App\Models\Calls\CallRecording;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\Product;
use App\Policies\Calls\CallPolicy;
use App\Services\Calls\CallRecordings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CallsTestHelpers;

beforeEach(function () {
    Storage::fake('local');

    $w = CallsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->other = $w['other'];
    $this->outsider = $w['outsider'];
    $this->call = $w['call'];
    $this->host = $w['host'];
    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, ...$parameters]);
});

test('starting a meeting creates the call with its host and goes straight to the meeting link', function () {
    $response = $this->actingAs($this->member)->post(($this->url)('calls.store'), ['title' => 'Design review']);

    $call = Call::query()->where('title', 'Design review')->sole();

    $response->assertRedirect(route('meet.show', $call->code));

    expect($call->host_id)->toBe($this->member->id)
        ->and($call->code)->toMatch('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/');

    $this->assertDatabaseHas('call_participants', ['call_id' => $call->id, 'user_id' => $this->member->id, 'role' => 'host', 'status' => 'admitted']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'call.created', 'user_id' => $this->member->id]);
});

test('guests are redirected to sign in before they can start a meeting', function () {
    $this->post(($this->url)('calls.store'))->assertRedirect(route('login'));
});

test('someone outside the organization gets 403 on its calls', function () {
    $this->actingAs($this->outsider)->get(($this->url)('calls.index'))->assertForbidden();
    $this->actingAs($this->outsider)->post(($this->url)('calls.store'))->assertForbidden();
});

test('a call started from an issue is linked to it and named after it', function () {
    $product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $issue = Issue::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $product->id, 'title' => 'Login is slow', 'identifier' => 'FRONT-7', 'prefix' => 'FRONT', 'number' => 7]);

    $this->actingAs($this->admin)->postJson(($this->url)('calls.store'), ['link_type' => 'issue', 'link_key' => 'front-7'])
        ->assertCreated()
        ->assertJsonPath('call.title', 'FRONT-7 · Login is slow');

    $this->assertDatabaseHas('call_links', ['linkable_type' => 'issue', 'linkable_id' => $issue->id]);

    $this->actingAs($this->admin)->getJson(($this->url)('calls.linked', ['type' => 'issue', 'key' => 'FRONT-7']))
        ->assertOk()
        ->assertJsonCount(1, 'calls');
});

test('linking to an issue that does not exist returns 404', function () {
    $this->actingAs($this->admin)->postJson(($this->url)('calls.store'), ['link_type' => 'issue', 'link_key' => 'NOPE-1'])->assertNotFound();
});

test('a pasted link or code resolves to the meeting', function () {
    $this->actingAs($this->member)->postJson(($this->url)('calls.lookup'), ['code' => 'https://tandem.test/meet/'.strtoupper($this->call->code).'?x=1'])
        ->assertOk()
        ->assertJsonPath('url', route('meet.show', $this->call->code));

    $this->actingAs($this->member)->postJson(($this->url)('calls.lookup'), ['code' => 'abc-defg-hij'])->assertNotFound();
});

test('the call policy lets in the host, attendees and administrators only', function () {
    $policy = app(CallPolicy::class);
    $memberCall = Call::factory()->ended()->create(['organization_id' => $this->organization->id, 'host_id' => $this->member->id]);
    CallParticipant::factory()->forUser($this->other)->create(['call_id' => $memberCall->id]);

    expect($policy->view($this->member, $memberCall))->toBeTrue()          // host
        ->and($policy->view($this->other, $memberCall))->toBeTrue()        // attended
        ->and($policy->view($this->admin, $memberCall))->toBeTrue()        // organization admin
        ->and($policy->view($this->outsider, $memberCall))->toBeFalse()
        ->and($policy->update($this->other, $memberCall))->toBeFalse()
        ->and($policy->delete($this->member, $memberCall))->toBeTrue()
        ->and($policy->deleteRecordings($this->other, $memberCall))->toBeFalse()
        ->and($policy->share($this->other, $memberCall))->toBeTrue();
});

test('a member who was only invited, never admitted, cannot open the history', function () {
    CallParticipant::factory()->forUser($this->member)->create(['call_id' => $this->call->id, 'status' => 'invited', 'admitted_at' => null]);

    $this->actingAs($this->member)->get(($this->url)('calls.show', ['call' => $this->call->uuid]))->assertForbidden();
});

test('a call of another organization returns 404 under this organization\'s address', function () {
    $foreign = Call::factory()->ended()->create(['organization_id' => Organization::factory()]);

    $this->actingAs($this->admin)->get(($this->url)('calls.show', ['call' => $foreign->uuid]))->assertNotFound();
});

test('the history lists only calls the member hosted or attended; administrators see all', function () {
    Call::factory()->ended()->create(['organization_id' => $this->organization->id, 'host_id' => $this->other->id, 'title' => 'Private 1:1']);
    Call::factory()->ended()->create(['organization_id' => $this->organization->id, 'host_id' => $this->member->id, 'title' => 'Mine']);

    $this->actingAs($this->member)->get(($this->url)('calls.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('calls/Index', false)->where('recent.data', fn ($calls) => collect($calls)->pluck('title')->all() === ['Mine']));

    $this->actingAs($this->admin)->get(($this->url)('calls.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('calls/Index', false)->has('recent.data', 2));
});

test('the history page carries chat, attendees and recordings without the share token', function () {
    CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->host->id, 'body' => 'Decision: ship Friday']);
    CallRecording::factory()->ready()->create(['call_id' => $this->call->id, 'share_token' => hash('sha256', 'secret')]);

    $this->actingAs($this->admin)->get(($this->url)('calls.show', ['call' => $this->call->uuid]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('calls/Show', false)
            ->where('messages.0.body', 'Decision: ship Friday')
            ->where('recordings.0.has_share_link', true)
            ->missing('recordings.0.share_token')
            ->missing('recordings.0.path')
            ->where('can.delete', true));
});

test('recording media streams to members with range support and refuses anything outside the manifest', function () {
    $recording = CallRecording::factory()->ready()->create(['call_id' => $this->call->id]);
    Storage::disk('local')->put(app(CallRecordings::class)->directory($recording).'/0001-microphone-p_a.webm', str_repeat('x', 1000));
    Storage::disk('local')->put(app(CallRecordings::class)->directory($recording).'/state.json', '{}');

    $media = fn (string $file) => ($this->url)('calls.recordings.media', ['call' => $this->call->uuid, 'recording' => $recording->uuid, 'file' => $file]);

    $this->actingAs($this->admin)->get($media('0001-microphone-p_a.webm'), ['Range' => 'bytes=0-99'])
        ->assertStatus(206)
        ->assertHeader('Content-Type', 'audio/webm')
        ->assertHeader('Content-Range', 'bytes 0-99/1000');

    $this->actingAs($this->admin)->get($media('state.json'))->assertNotFound();
    $this->actingAs($this->other)->get($media('0001-microphone-p_a.webm'))->assertForbidden();
});

test('a share link works without an account until it is revoked, and its media needs a valid signature', function () {
    $recording = CallRecording::factory()->ready()->create(['call_id' => $this->call->id]);
    Storage::disk('local')->put(app(CallRecordings::class)->directory($recording).'/0001-microphone-p_a.webm', 'audio');
    $share = ($this->url)('calls.recordings.share', ['call' => $this->call->uuid, 'recording' => $recording->uuid]);

    $url = $this->actingAs($this->admin)->postJson($share)->assertOk()->json('url');
    auth()->logout();

    $trackUrl = null;
    $this->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$trackUrl) {
        $page->component('meet/Recording', false)->has('recording.tracks', 2);
        $trackUrl = $page->toArray()['props']['recording']['tracks'][0]['url'];
    });

    $this->get($trackUrl)->assertOk();
    $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=forged', $trackUrl))->assertForbidden();
    $this->get(route('meet.recordings.media', ['recording' => $recording->uuid, 'file' => '0001-microphone-p_a.webm']))->assertForbidden();

    $this->actingAs($this->admin)->deleteJson($share)->assertOk();
    auth()->logout();
    $this->get($url)->assertNotFound();
});

test('an expired share link returns 404', function () {
    CallRecording::factory()->ready()->create(['call_id' => $this->call->id, 'share_token' => hash('sha256', 'old-token'), 'share_expires_at' => now()->subMinute()]);

    $this->get(route('meet.recordings.shared', 'old-token'))->assertNotFound();
});

test('requesting an export queues one build and reuses it on a second request', function () {
    Queue::fake();
    $recording = CallRecording::factory()->ready()->create(['call_id' => $this->call->id]);
    $url = ($this->url)('calls.recordings.exports.store', ['call' => $this->call->uuid, 'recording' => $recording->uuid]);

    $first = $this->actingAs($this->admin)->postJson($url, ['kind' => 'audio'])->assertStatus(202)->json('export.uuid');
    $second = $this->actingAs($this->admin)->postJson($url, ['kind' => 'audio'])->assertStatus(202)->json('export.uuid');

    expect($second)->toBe($first);
    Queue::assertPushedOn('calls-media', BuildRecordingExport::class);
    Queue::assertPushed(BuildRecordingExport::class, 1);

    $this->actingAs($this->admin)->postJson($url, ['kind' => 'gif'])->assertUnprocessable();
});

test('an export download is only served once it is ready', function () {
    $recording = CallRecording::factory()->ready()->create(['call_id' => $this->call->id]);
    $export = $recording->exports()->create(['kind' => 'audio', 'status' => ExportStatus::Processing, 'disk' => 'local']);
    $url = ($this->url)('calls.recordings.exports.download', ['call' => $this->call->uuid, 'recording' => $recording->uuid, 'export' => $export->uuid]);

    $this->actingAs($this->admin)->get($url)->assertNotFound();

    Storage::disk('local')->put('calls/recordings/x/exports/a.m4a', 'audio');
    $export->update(['status' => ExportStatus::Ready, 'path' => 'calls/recordings/x/exports/a.m4a', 'filename' => 'weekly-sync-audio.m4a']);

    $response = $this->actingAs($this->admin)->get($url)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('weekly-sync-audio.m4a');
});

test('only the host or an administrator deletes a recording, and its files go with it', function () {
    $memberCall = Call::factory()->ended()->create(['organization_id' => $this->organization->id, 'host_id' => $this->member->id]);
    CallParticipant::factory()->forUser($this->other)->create(['call_id' => $memberCall->id]);
    $recording = CallRecording::factory()->ready()->create(['call_id' => $memberCall->id]);
    $directory = app(CallRecordings::class)->directory($recording);
    Storage::disk('local')->put($directory.'/0001-microphone-p_a.webm', 'audio');
    $url = ($this->url)('calls.recordings.destroy', ['call' => $memberCall->uuid, 'recording' => $recording->uuid]);

    $this->actingAs($this->other)->delete($url)->assertForbidden();
    $this->assertModelExists($recording);

    $this->actingAs($this->member)->delete($url)->assertRedirect();
    $this->assertModelMissing($recording);
    Storage::disk('local')->assertMissing($directory.'/0001-microphone-p_a.webm');
    Storage::disk('local')->assertMissing(dirname($directory));
    $this->assertDatabaseHas('audit_logs', ['action' => 'call.recording_deleted', 'user_id' => $this->member->id]);
});

test('organization call settings are for administrators and become the defaults of new calls', function () {
    $payload = ['waiting_room' => false, 'screen_share' => 'hosts', 'recording' => 'members', 'guest_uploads' => false, 'auto_email_transcript' => true, 'recording_retention_days' => 90];

    $this->actingAs($this->member)->put(($this->url)('calls.settings'), $payload)->assertForbidden();
    $this->actingAs($this->admin)->put(($this->url)('calls.settings'), $payload)->assertRedirect();

    $call = Call::factory()->create(['organization_id' => $this->organization->id]);

    expect($call->setting('waiting_room'))->toBeFalse()
        ->and($call->setting('recording'))->toBe('members')
        ->and(app(CallRecordings::class)->retentionDays($this->organization->fresh()))->toBe(90);
});

test('diagnostics are for administrators', function () {
    $this->actingAs($this->member)->getJson(($this->url)('calls.diagnostics'))->assertForbidden();

    $this->actingAs($this->admin)->getJson(($this->url)('calls.diagnostics'))
        ->assertOk()
        ->assertJsonPath('media.configured', true)
        ->assertJsonPath('recorder.configured', true)
        ->assertJsonStructure(['storage' => ['free_bytes', 'total_bytes'], 'recordings' => ['active', 'failed_last_week']]);
});

test('a signed file link from a transcript opens without an account and a tampered one returns 403', function () {
    $message = CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->host->id]);
    Storage::disk('local')->put('calls/x/notes.txt', 'hello');
    $file = $this->call->files()->create(['message_id' => $message->id, 'participant_id' => $this->host->id, 'filename' => 'notes.txt', 'mime_type' => 'text/plain', 'size_bytes' => 5, 'disk' => 'local', 'path' => 'calls/x/notes.txt']);

    $this->get(URL::temporarySignedRoute('meet.files.signed', now()->addDay(), ['file' => $file->uuid]))->assertOk();
    $this->get(route('meet.files.signed', ['file' => $file->uuid]))->assertForbidden();
    $this->get(URL::temporarySignedRoute('meet.files.signed', now()->subMinute(), ['file' => $file->uuid]))->assertForbidden();
});

test('deleting a meeting removes its chat files and recordings', function () {
    Storage::disk('local')->put('calls/x/notes.txt', 'hello');
    $this->call->files()->create(['participant_id' => $this->host->id, 'filename' => 'notes.txt', 'mime_type' => 'text/plain', 'size_bytes' => 5, 'disk' => 'local', 'path' => 'calls/x/notes.txt']);
    $recording = CallRecording::factory()->ready()->create(['call_id' => $this->call->id]);

    $this->actingAs($this->admin)->delete(($this->url)('calls.destroy', ['call' => $this->call->uuid]))->assertRedirect(($this->url)('calls.index'));

    $this->assertSoftDeleted($this->call);
    $this->assertModelMissing($recording);
    Storage::disk('local')->assertMissing('calls/x/notes.txt');
});
