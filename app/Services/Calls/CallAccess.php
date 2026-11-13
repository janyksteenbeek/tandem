<?php

namespace App\Services\Calls;

use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Works out who a request is inside a call. Signed-in people are found by their user id;
 * guests by the participant id their session was given when they asked to join. A guest's
 * session only ever unlocks that one participant in that one call.
 */
class CallAccess
{
    public const ATTRIBUTE = 'callParticipant';

    public function participantFor(Request $request, Call $call): ?CallParticipant
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $call->participants()->where('user_id', $user->id)->first();
        }

        return $this->guestFor($request, $call);
    }

    public function guestFor(Request $request, Call $call): ?CallParticipant
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get($this->sessionKey($call));

        if (! is_int($id)) {
            return null;
        }

        return $call->participants()->whereKey($id)->whereNull('user_id')->first();
    }

    public function rememberGuest(Request $request, CallParticipant $participant): void
    {
        $request->session()->put($this->sessionKey($participant->call), $participant->id);
    }

    public function forgetGuest(Request $request, Call $call): void
    {
        $request->session()->forget($this->sessionKey($call));
    }

    /**
     * The participant the EnsureCallParticipant middleware resolved for this request.
     */
    public function current(Request $request): CallParticipant
    {
        $participant = $request->attributes->get(self::ATTRIBUTE);

        abort_unless($participant instanceof CallParticipant, 403);

        return $participant;
    }

    protected function sessionKey(Call $call): string
    {
        return 'calls.guest.'.$call->id;
    }
}
