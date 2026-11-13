<?php

namespace Tests\Support;

use App\Enums\Calls\ParticipantRole;
use App\Events\Calls\Broadcast\CallMessageChanged;
use App\Events\Calls\Broadcast\CallParticipantUpdated;
use App\Events\Calls\Broadcast\CallUpdated;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * The usual cast for call tests: the projects workspace plus a call hosted by its admin, with
 * the media server and the recorder replaced by fakes so nothing leaves the test.
 */
class CallsTestHelpers
{
    public const RECORDER_SECRET = 'test-recorder-secret';

    /**
     * What the fake media server answers per room-service method (e.g. `ListParticipants`).
     * Reset by workspace().
     *
     * @var array<string, array<string, mixed>>
     */
    public static array $mediaResponses = [];

    /**
     * @return array{organization: Organization, admin: User, member: User, other: User, outsider: User, call: Call, host: CallParticipant}
     */
    public static function workspace(): array
    {
        config([
            'calls.livekit.url' => 'http://livekit.test',
            'calls.livekit.public_url' => 'wss://meet.test',
            'calls.livekit.api_key' => 'testkey',
            'calls.livekit.api_secret' => 'test-secret-that-is-long-enough-0123456789',
            'calls.recorder.url' => 'http://recorder.test',
            'calls.recorder.secret' => self::RECORDER_SECRET,
        ]);

        // The room is told about changes over Reverb; tests assert the dispatch, not the socket.
        Event::fake([CallUpdated::class, CallParticipantUpdated::class, CallMessageChanged::class]);

        Http::preventStrayRequests();
        self::$mediaResponses = [];
        Http::fake([
            'livekit.test/*' => fn (Request $request) => Http::response(self::$mediaResponses[basename($request->url())] ?? []),
            'recorder.test/*' => Http::response(['status' => 'starting'], 202),
        ]);

        $w = ProjectsTestHelpers::workspace();

        $call = Call::factory()->create(['organization_id' => $w['organization']->id, 'host_id' => $w['admin']->id, 'title' => 'Weekly sync']);
        $host = CallParticipant::factory()->host($w['admin'])->create(['call_id' => $call->id]);

        return [
            'organization' => $w['organization'],
            'admin' => $w['admin'],
            'member' => $w['member'],
            'other' => $w['other'],
            'outsider' => $w['outsider'],
            'call' => $call,
            'host' => $host,
        ];
    }

    /**
     * The session a guest's browser holds after being given this participant.
     *
     * @return array<string, int>
     */
    public static function guestSession(CallParticipant $participant): array
    {
        return ['calls.guest.'.$participant->call_id => $participant->id];
    }

    public static function memberIn(Call $call, User $user, ParticipantRole $role = ParticipantRole::Member): CallParticipant
    {
        return CallParticipant::factory()->forUser($user, $role)->create(['call_id' => $call->id]);
    }

    /**
     * Decode the claims of a media token without verifying it.
     *
     * @return array<string, mixed>
     */
    public static function claims(string $token): array
    {
        return (array) json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }
}
