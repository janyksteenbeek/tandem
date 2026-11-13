<?php

use App\Events\Calls\Broadcast\CallMessageChanged;
use App\Models\Calls\CallMessage;
use App\Models\Calls\CallParticipant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CallsTestHelpers;

beforeEach(function () {
    Storage::fake('local');

    $w = CallsTestHelpers::workspace();
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->outsider = $w['outsider'];
    $this->call = $w['call'];
    $this->host = $w['host'];
    $this->guest = CallParticipant::factory()->create(['call_id' => $this->call->id, 'name' => 'Grace Guest']);
    $this->asGuest = fn () => $this->withSession(CallsTestHelpers::guestSession($this->guest));
});

test('a guest posts a message that is stored as plain text and announced to the room', function () {
    ($this->asGuest)()->postJson(route('meet.messages.store', $this->call->code), ['body' => "  <b>hi</b> there\r\nhttps://example.com  ", 'client_id' => 'c-1'])
        ->assertCreated()
        ->assertJsonPath('message.body', "<b>hi</b> there\nhttps://example.com")
        ->assertJsonPath('message.author.name', 'Grace Guest')
        ->assertJsonPath('message.client_id', 'c-1');

    expect($this->call->fresh()->message_count)->toBe(1);
    Event::assertDispatched(CallMessageChanged::class, fn (CallMessageChanged $event) => $event->action === 'created');
});

test('retrying an optimistic message with the same client id stores it once', function () {
    $first = ($this->asGuest)()->postJson(route('meet.messages.store', $this->call->code), ['body' => 'Once', 'client_id' => 'c-1'])->json('message.uuid');
    $second = ($this->asGuest)()->postJson(route('meet.messages.store', $this->call->code), ['body' => 'Once', 'client_id' => 'c-1'])->json('message.uuid');

    expect($second)->toBe($first)->and(CallMessage::count())->toBe(1);
});

test('an empty message without a file is rejected', function () {
    ($this->asGuest)()->postJson(route('meet.messages.store', $this->call->code), ['body' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body' => 'Write a message or attach a file.']);
});

test('chat returns 403 to someone who is not in the call', function () {
    $this->actingAs($this->outsider)->getJson(route('meet.messages.index', $this->call->code))->assertForbidden();
    $this->actingAs($this->outsider)->postJson(route('meet.messages.store', $this->call->code), ['body' => 'Hello'])->assertForbidden();

    expect(CallMessage::count())->toBe(0);
});

test('a waiting guest cannot read the chat', function () {
    $waiting = CallParticipant::factory()->waiting()->create(['call_id' => $this->call->id]);
    CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->host->id]);

    $this->withSession(CallsTestHelpers::guestSession($waiting))->getJson(route('meet.messages.index', $this->call->code))->assertForbidden();
});

test('a reconnecting browser fetches only what it missed', function () {
    $messages = CallMessage::factory()->count(3)->create(['call_id' => $this->call->id, 'participant_id' => $this->host->id]);

    $ids = ($this->asGuest)()->getJson(route('meet.messages.index', [$this->call->code, 'after' => $messages[0]->id]))->assertOk()->json('messages.*.id');

    expect($ids)->toBe([$messages[1]->id, $messages[2]->id]);
});

test('people edit only their own messages', function () {
    $mine = CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->guest->id, 'body' => 'Tpyo']);
    $theirs = CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->host->id]);

    ($this->asGuest)()->patchJson(route('meet.messages.update', [$this->call->code, $mine->uuid]), ['body' => 'Typo'])
        ->assertOk()
        ->assertJsonPath('message.body', 'Typo');

    expect($mine->fresh()->edited_at)->not->toBeNull();

    ($this->asGuest)()->patchJson(route('meet.messages.update', [$this->call->code, $theirs->uuid]), ['body' => 'Hijacked'])->assertForbidden();
});

test('a host may delete anyone\'s message; a guest only their own', function () {
    $guestMessage = CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->guest->id]);
    $hostMessage = CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->host->id]);

    ($this->asGuest)()->deleteJson(route('meet.messages.destroy', [$this->call->code, $hostMessage->uuid]))->assertForbidden();

    $this->actingAs($this->admin)->deleteJson(route('meet.messages.destroy', [$this->call->code, $guestMessage->uuid]))->assertOk();

    $this->assertSoftDeleted($guestMessage);
    Event::assertDispatched(CallMessageChanged::class, fn (CallMessageChanged $event) => $event->action === 'deleted');

    // The tombstone stays in the history without its text.
    $this->actingAs($this->admin)->getJson(route('meet.messages.index', $this->call->code))
        ->assertJsonPath('messages.0.deleted', true)
        ->assertJsonPath('messages.0.body', null);
});

test('reactions toggle per participant and replies carry their parent', function () {
    $message = CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->host->id, 'body' => 'Ship it?']);
    $url = route('meet.messages.react', [$this->call->code, $message->uuid]);

    ($this->asGuest)()->postJson($url, ['emoji' => '👍', 'on' => true])->assertOk()->assertJsonPath('message.reactions.0.count', 1);
    ($this->asGuest)()->postJson($url, ['emoji' => '👍', 'on' => true])->assertOk()->assertJsonPath('message.reactions.0.count', 1);
    ($this->asGuest)()->postJson($url, ['emoji' => '👍', 'on' => false])->assertOk()->assertJsonCount(0, 'message.reactions');

    ($this->asGuest)()->postJson(route('meet.messages.store', $this->call->code), ['body' => 'Yes', 'parent_uuid' => $message->uuid])
        ->assertCreated()
        ->assertJsonPath('message.parent.uuid', $message->uuid)
        ->assertJsonPath('message.parent.excerpt', 'Ship it?');
});

test('chat is read-only once the meeting has ended', function () {
    $this->call->update(['status' => 'ended', 'ended_at' => now()]);

    ($this->asGuest)()->postJson(route('meet.messages.store', $this->call->code), ['body' => 'Late'])->assertForbidden();
    ($this->asGuest)()->getJson(route('meet.messages.index', $this->call->code))->assertOk();
});

test('an uploaded file is stored privately and attached to the message that sends it', function () {
    $uuid = ($this->asGuest)()->postJson(route('meet.files.store', $this->call->code), ['file' => UploadedFile::fake()->create('notes.pdf', 120, 'application/pdf')])
        ->assertCreated()
        ->assertJsonPath('file.filename', 'notes.pdf')
        ->json('file.uuid');

    $file = $this->call->files()->where('uuid', $uuid)->sole();
    Storage::disk('local')->assertExists($file->path);
    expect($file->path)->toStartWith('calls/'.$this->call->uuid.'/files/');

    ($this->asGuest)()->postJson(route('meet.messages.store', $this->call->code), ['files' => [$uuid]])
        ->assertCreated()
        ->assertJsonPath('message.files.0.uuid', $uuid);

    expect($this->call->fresh()->file_count)->toBe(1);
});

test('one participant cannot attach a file another participant uploaded', function () {
    $file = $this->call->files()->create(['participant_id' => $this->host->id, 'filename' => 'private.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'disk' => 'local', 'path' => 'calls/x/private.pdf']);

    ($this->asGuest)()->postJson(route('meet.messages.store', $this->call->code), ['files' => [$file->uuid]])->assertUnprocessable();

    expect($file->fresh()->message_id)->toBeNull();
});

test('dangerous file types are refused', function () {
    ($this->asGuest)()->postJson(route('meet.files.store', $this->call->code), ['file' => UploadedFile::fake()->create('run.exe', 10)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'That file type is not allowed.']);
});

test('guests cannot upload when the host switched guest uploads off, members still can', function () {
    $this->call->update(['settings' => ['guest_uploads' => false]]);

    ($this->asGuest)()->postJson(route('meet.files.store', $this->call->code), ['file' => UploadedFile::fake()->create('notes.pdf', 10)])->assertForbidden();
    $this->actingAs($this->admin)->postJson(route('meet.files.store', $this->call->code), ['file' => UploadedFile::fake()->create('notes.pdf', 10)])->assertCreated();
});

test('a file downloads for people in the call and returns 403 to everyone else', function () {
    Storage::disk('local')->put('calls/x/notes.txt', 'hello');
    $file = $this->call->files()->create(['participant_id' => $this->host->id, 'filename' => 'notes.txt', 'mime_type' => 'text/plain', 'size_bytes' => 5, 'disk' => 'local', 'path' => 'calls/x/notes.txt']);
    $url = route('meet.files.show', [$this->call->code, $file->uuid]);

    ($this->asGuest)()->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->flushSession();
    $this->get($url)->assertForbidden();
    $this->actingAs($this->outsider)->get($url)->assertForbidden();
});

test('an svg is never served inline', function () {
    Storage::disk('local')->put('calls/x/logo.svg', '<svg onload="alert(1)"/>');
    $file = $this->call->files()->create(['participant_id' => $this->host->id, 'filename' => 'logo.svg', 'mime_type' => 'image/svg+xml', 'size_bytes' => 5, 'disk' => 'local', 'path' => 'calls/x/logo.svg']);

    $response = ($this->asGuest)()->get(route('meet.files.show', [$this->call->code, $file->uuid]))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});
