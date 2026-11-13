<?php

use App\Enums\Calls\DeliveryStatus;
use App\Jobs\Calls\SendCallTranscript;
use App\Mail\Calls\CallTranscriptMail;
use App\Models\Calls\CallMessage;
use App\Models\Calls\CallParticipant;
use App\Models\Calls\CallRecording;
use App\Models\Calls\CallTranscriptDelivery;
use App\Services\Calls\CallActions;
use App\Services\Calls\CallMailer;
use App\Services\Calls\CallTranscripts;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CallsTestHelpers;

beforeEach(function () {
    Storage::fake('local');

    $w = CallsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->other = $w['other'];
    $this->call = $w['call'];
    $this->host = $w['host'];

    $this->call->update(['status' => 'live', 'started_at' => now()->subMinutes(30)]);
    $this->host->update(['status' => 'joined', 'joined_at' => now()->subMinutes(30)]);
    $this->guest = CallParticipant::factory()->joined()->create(['call_id' => $this->call->id, 'name' => 'Grace Guest', 'email' => 'grace@example.com']);

    $first = CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->host->id, 'body' => 'Shall we ship <b>Friday</b>?']);
    $reply = CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->guest->id, 'body' => 'Yes, see the plan', 'parent_id' => $first->id]);
    $this->call->files()->create(['message_id' => $reply->id, 'participant_id' => $this->guest->id, 'filename' => 'plan.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 2048, 'disk' => 'local', 'path' => 'calls/x/plan.pdf']);
    CallMessage::factory()->create(['call_id' => $this->call->id, 'participant_id' => $this->guest->id, 'body' => 'Oops', 'deleted_at' => now()]);
    $this->call->update(['message_count' => 2, 'file_count' => 1]);

    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, 'call' => $this->call->uuid, ...$parameters]);
});

test('the transcript lists the meeting, who attended and the chat in order with file links', function () {
    $text = $this->actingAs($this->admin)->getJson(($this->url)('calls.transcript'))->assertOk()->json('text');

    expect($text)
        ->toContain('Weekly sync')
        ->toContain('Participants: '.$this->admin->name.', Grace Guest (guest)')
        ->toContain($this->admin->name.': Shall we ship <b>Friday</b>?')
        ->toContain('Grace Guest (replying to '.$this->admin->name.'): Yes, see the plan')
        ->toContain('Attachment: plan.pdf (2 KB) http')
        ->toContain('(message deleted)')
        ->not->toContain('Oops');

    expect(strpos($text, 'Shall we ship'))->toBeLessThan(strpos($text, 'Yes, see the plan'));
});

test('file and recording links can be left out', function () {
    CallRecording::factory()->ready()->create(['call_id' => $this->call->id]);

    $with = app(CallTranscripts::class)->text($this->call);
    $without = app(CallTranscripts::class)->text($this->call, includeFiles: false, includeRecording: false);

    expect($with)->toContain('/meet/recordings/watch/')->toContain('/meet/files/')
        ->and($without)->not->toContain('/meet/recordings/')->not->toContain('/meet/files/')
        ->and($without)->toContain('Attachment: plan.pdf');
});

test('the transcript downloads as a text file', function () {
    $response = $this->actingAs($this->admin)->get(($this->url)('calls.transcript', ['download' => 1]))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="weekly-sync-chat.txt"');
});

test('emailing the chat queues one tracked delivery per distinct recipient and is audited', function () {
    Queue::fake();

    $this->actingAs($this->admin)->postJson(($this->url)('calls.transcript.email'), [
        'recipients' => [['email' => 'Grace@Example.com', 'name' => 'Grace'], ['email' => 'grace@example.com'], ['email' => 'client@example.org']],
        'include_recording' => false,
    ])->assertStatus(202)->assertJsonPath('queued', 2);

    $this->assertDatabaseHas('call_transcript_deliveries', ['call_id' => $this->call->id, 'email' => 'grace@example.com', 'status' => 'queued', 'include_recording' => false, 'include_files' => true, 'sent_by_id' => $this->admin->id]);
    Queue::assertPushed(SendCallTranscript::class, 2);
    $this->assertDatabaseHas('audit_logs', ['action' => 'call.transcript_emailed', 'user_id' => $this->admin->id]);
});

test('recipients must be valid addresses', function () {
    $this->actingAs($this->admin)->postJson(($this->url)('calls.transcript.email'), ['recipients' => [['email' => 'not-an-address']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recipients.0.email']);

    $this->actingAs($this->admin)->postJson(($this->url)('calls.transcript.email'), ['recipients' => []])->assertUnprocessable();
});

test('a member who was not in the call cannot email its chat', function () {
    Queue::fake();

    $this->actingAs($this->other)->postJson(($this->url)('calls.transcript.email'), ['recipients' => [['email' => 'leak@example.org']]])->assertForbidden();

    Queue::assertNothingPushed();
});

test('the delivery job sends the HTML and text transcript and records that it was sent', function () {
    Mail::fake();
    $delivery = CallTranscriptDelivery::create(['call_id' => $this->call->id, 'email' => 'grace@example.com', 'status' => DeliveryStatus::Queued])->refresh();

    (new SendCallTranscript($delivery))->handle(app(CallMailer::class));

    Mail::assertSent(CallTranscriptMail::class, fn (CallTranscriptMail $mail) => $mail->hasTo('grace@example.com') && $mail->hasSubject('Meeting chat: Weekly sync'));

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent)
        ->and($delivery->fresh()->sent_at)->not->toBeNull();
});

test('the transcript email escapes what people typed and links the shared file', function () {
    $mail = new CallTranscriptMail($this->call);

    $mail->assertSeeInHtml('Shall we ship &lt;b&gt;Friday&lt;/b&gt;?', false);
    $mail->assertSeeInHtml('plan.pdf');
    $mail->assertSeeInHtml('/meet/files/');
    $mail->assertDontSeeInHtml('Oops');
    $mail->assertSeeInText('Grace Guest (replying to '.$this->admin->name.'): Yes, see the plan');
});

test('a failed delivery keeps the error and can be resent', function () {
    Queue::fake();
    $delivery = CallTranscriptDelivery::create(['call_id' => $this->call->id, 'email' => 'grace@example.com', 'status' => DeliveryStatus::Queued]);

    (new SendCallTranscript($delivery))->failed(new RuntimeException('Connection to smtp.example.com refused'));

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->fresh()->error)->toBe('Connection to smtp.example.com refused');

    $this->actingAs($this->admin)->postJson(($this->url)('calls.transcript.resend', ['transcriptDelivery' => $delivery->uuid]))->assertStatus(202);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Queued)->and($delivery->fresh()->error)->toBeNull();
    Queue::assertPushed(SendCallTranscript::class, 1);
});

test('ending a meeting emails the chat to those who attended only when the host switched that on', function () {
    Queue::fake();
    CallParticipant::factory()->create(['call_id' => $this->call->id, 'email' => 'never-joined@example.com']);
    CallParticipant::factory()->waiting()->create(['call_id' => $this->call->id, 'email' => 'waiting@example.com']);

    $this->call->update(['settings' => ['auto_email_transcript' => true]]);
    app(CallActions::class)->end($this->call->fresh());

    $emails = CallTranscriptDelivery::query()->where('is_automatic', true)->pluck('email')->sort()->values()->all();

    expect($emails)->toBe(collect([$this->admin->email, 'grace@example.com'])->sort()->values()->all());
    Queue::assertPushed(SendCallTranscript::class, 2);
});

test('nothing is emailed automatically by default', function () {
    Queue::fake();

    app(CallActions::class)->end($this->call->fresh());

    expect(CallTranscriptDelivery::count())->toBe(0);
    Queue::assertNotPushed(SendCallTranscript::class);
});
