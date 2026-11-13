<?php

namespace App\Http\Controllers\Calls;

use App\Enums\Calls\ParticipantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Calls\CallParticipantResource;
use App\Http\Resources\Calls\CallRecordingResource;
use App\Http\Resources\Calls\CallResource;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Services\Calls\CallAccess;
use App\Services\Calls\CallActions;
use App\Services\Calls\JoinRefused;
use App\Services\Calls\Media\AccessToken;
use App\Services\Calls\ParticipantAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The meeting itself, reached through its shareable link. Open to anyone with the link; what
 * they get depends on who they are and what the call allows.
 */
class MeetController extends Controller
{
    public function __construct(protected CallAccess $access, protected CallActions $actions) {}

    public function show(Request $request, Call $call): Response
    {
        $user = $request->user();
        $participant = $this->access->participantFor($request, $call);
        $invite = $request->string('invite')->toString();
        $invitation = $invite !== '' ? $call->participants()
            ->where('invite_token', hash('sha256', $invite))
            ->where('status', ParticipantStatus::Invited->value)
            ->first() : null;

        return Inertia::render('meet/Room', [
            'call' => [
                'uuid' => $participant?->isInside() ? $call->uuid : null,
                'code' => $call->code,
                'title' => $call->displayTitle(),
                'organization' => $call->organization->name,
                'status' => $call->status->value,
                'has_password' => $call->hasPassword(),
                'waiting_room' => (bool) $call->setting('waiting_room'),
                'locked' => (bool) $call->setting('locked'),
                'is_recording' => $call->activeRecording()->exists(),
                'history_url' => $user && $user->can('view', $call)
                    ? route('calls.show', ['current_organization' => $call->organization->slug, 'call' => $call->uuid])
                    : null,
            ],
            'viewer' => [
                'name' => $user->name ?? $participant->name ?? $invitation->name ?? null,
                'avatar' => $user?->avatarUrl(),
                'is_signed_in' => $user !== null,
                'role' => $this->actions->roleFor($call, $user)->value,
                'status' => $participant?->status->value,
                'invite' => $invitation !== null ? $invite : null,
                'calls_url' => $user?->currentOrganization ? route('calls.index', ['current_organization' => $user->currentOrganization->slug]) : null,
            ],
            'media' => [
                'configured' => app(AccessToken::class)->isConfigured(),
            ],
        ]);
    }

    /**
     * Ask to enter. Answers with where that leaves the person: admitted, waiting or denied.
     */
    public function join(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate([
            'name' => [$request->user() ? 'nullable' : 'required', 'string', 'min:1', 'max:80'],
            'password' => ['nullable', 'string', 'max:200'],
            'invite' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $participant = $this->actions->join(
                $call,
                $request->user(),
                (string) ($data['name'] ?? ''),
                $data['password'] ?? null,
                $data['invite'] ?? null,
                $this->access->participantFor($request, $call),
            );
        } catch (JoinRefused $refusal) {
            return response()->json(['message' => $refusal->getMessage(), 'reason' => $refusal->reason], $refusal->reason === JoinRefused::PASSWORD ? 422 : 403);
        }

        if ($request->user() === null) {
            $this->access->rememberGuest($request, $participant);
        }

        return response()->json($this->standing($participant));
    }

    /**
     * Polled by someone in the waiting room until a host answers.
     */
    public function status(Request $request, Call $call): JsonResponse
    {
        $participant = $this->access->participantFor($request, $call);

        abort_if($participant === null, 404);

        return response()->json([...$this->standing($participant), 'call_status' => $call->status->value]);
    }

    /**
     * Hand out a short-lived media token. Asked for on every (re)connect, so a token is never
     * stored in the page longer than it takes to use it.
     */
    public function credentials(Request $request, Call $call): JsonResponse
    {
        try {
            return response()->json($this->actions->mediaCredentials($this->access->current($request)));
        } catch (JoinRefused $refusal) {
            return response()->json(['message' => $refusal->getMessage(), 'reason' => $refusal->reason], 403);
        }
    }

    /**
     * The browser reports that its media connection is up. The media server's webhook says
     * the same; whichever arrives first counts.
     */
    public function connected(Request $request, Call $call): JsonResponse
    {
        $this->actions->markConnected($this->access->current($request));

        return response()->json(['connected' => true]);
    }

    public function leave(Request $request, Call $call): JsonResponse
    {
        $this->actions->markDisconnected($this->access->current($request));

        return response()->json(['left' => true]);
    }

    /**
     * Everything the room needs after connecting or reconnecting.
     */
    public function state(Request $request, Call $call): JsonResponse
    {
        $me = $this->access->current($request);
        $abilities = ParticipantAbilities::for($me);
        $call->loadMissing(['organization', 'host']);

        $visible = [ParticipantStatus::Admitted, ParticipantStatus::Joined, ParticipantStatus::Left];

        if ($abilities->manage()) {
            $visible[] = ParticipantStatus::Waiting;
        }

        $participants = $call->participants()
            ->with('user')
            ->whereIn('status', array_map(fn (ParticipantStatus $status) => $status->value, $visible))
            ->orderBy('id')
            ->get();

        $recording = $call->recordings()->with('startedBy')->latest('id')->first();

        return response()->json([
            'call' => CallResource::make($call)->resolve(),
            'me' => [
                ...CallParticipantResource::make($me->loadMissing('user'))->resolve(),
                'can' => $abilities->toArray(),
                'last_read_message_id' => $me->last_read_message_id,
                'recording_consent_at' => $me->recording_consent_at?->toISOString(),
            ],
            'participants' => CallParticipantResource::collection($participants)->resolve(),
            'recording' => $recording ? CallRecordingResource::make($recording)->resolve() : null,
            'unread_count' => $call->messages()->where('id', '>', (int) $me->last_read_message_id)->where('participant_id', '!=', $me->id)->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function standing(CallParticipant $participant): array
    {
        return [
            'status' => $participant->status->value,
            'participant' => CallParticipantResource::make($participant->loadMissing('user'))->resolve(),
            'call_uuid' => $participant->isInside() ? $participant->call->uuid : null,
        ];
    }
}
