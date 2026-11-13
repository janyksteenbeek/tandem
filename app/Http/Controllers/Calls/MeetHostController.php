<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Http\Resources\Calls\CallParticipantResource;
use App\Http\Resources\Calls\CallResource;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Services\Calls\CallAccess;
use App\Services\Calls\CallActions;
use App\Services\Calls\CallInvitations;
use App\Services\Calls\ParticipantAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What hosts do to a running meeting. Every route here sits behind the `host` check of
 * EnsureCallParticipant, except inviting, which members may do as well.
 */
class MeetHostController extends Controller
{
    public function __construct(protected CallAccess $access, protected CallActions $actions) {}

    public function admit(Request $request, Call $call, CallParticipant $participant): JsonResponse
    {
        $this->actions->admit($participant->setRelation('call', $call), $this->access->current($request));

        return $this->participant($participant);
    }

    public function deny(Request $request, Call $call, CallParticipant $participant): JsonResponse
    {
        $this->actions->deny($participant->setRelation('call', $call), $this->access->current($request));

        return $this->participant($participant);
    }

    public function remove(Request $request, Call $call, CallParticipant $participant): JsonResponse
    {
        $me = $this->access->current($request);

        abort_if($participant->is($me), 422, 'Leave the meeting instead of removing yourself.');
        abort_if($participant->isHost(), 403, 'A host cannot be removed.');

        $this->actions->remove($participant->setRelation('call', $call), $me);

        return $this->participant($participant);
    }

    public function mute(Request $request, Call $call, CallParticipant $participant): JsonResponse
    {
        return response()->json(['muted' => $this->actions->mute($participant->setRelation('call', $call), $this->access->current($request))]);
    }

    public function settings(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'waiting_room' => ['sometimes', 'boolean'],
            'locked' => ['sometimes', 'boolean'],
            'screen_share' => ['sometimes', Rule::in(['everyone', 'hosts'])],
            'recording' => ['sometimes', Rule::in(['hosts', 'members'])],
            'guest_uploads' => ['sometimes', 'boolean'],
            'auto_email_transcript' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:200'],
        ]);

        if (array_key_exists('title', $data)) {
            $this->actions->rename($call, $data['title']);
        }

        $call = $this->actions->updateSettings(
            $call,
            $this->access->current($request),
            $data,
            $data['password'] ?? null,
            clearPassword: array_key_exists('password', $data) && blank($data['password']),
        );

        return response()->json(['call' => CallResource::make($call->loadMissing(['organization', 'host']))->resolve()]);
    }

    public function end(Request $request, Call $call): JsonResponse
    {
        $this->actions->end($call, $this->access->current($request), 'host');

        return response()->json(['ended' => true]);
    }

    public function invite(Request $request, Call $call, CallInvitations $invitations): JsonResponse
    {
        $me = $this->access->current($request);

        abort_unless(ParticipantAbilities::for($me)->invite() && $me->user !== null, 403, 'Only members can invite people.');

        $data = $request->validate([
            'emails' => ['nullable', 'array', 'max:25'],
            'emails.*' => ['email:rfc', 'max:254'],
            'user_ids' => ['nullable', 'array', 'max:25'],
            'user_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        abort_if(empty($data['emails']) && empty($data['user_ids']), 422, 'Choose at least one person to invite.');

        $invited = $invitations->invite($call, $me->user, $data['emails'] ?? [], array_values(array_map('intval', $data['user_ids'] ?? [])), $data['note'] ?? null);

        return response()->json(['invited' => $invited->count()]);
    }

    protected function participant(CallParticipant $participant): JsonResponse
    {
        return response()->json(['participant' => CallParticipantResource::make($participant->loadMissing('user'))->resolve()]);
    }
}
