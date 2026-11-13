<?php

namespace App\Http\Middleware;

use App\Models\Calls\Call;
use App\Services\Calls\CallAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the in-call endpoints: the request must come from someone who was let into this
 * call, signed in or not. With `host` it must be a host.
 */
class EnsureCallParticipant
{
    public function __construct(protected CallAccess $access) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $call = $request->route('call');

        abort_unless($call instanceof Call, 404);

        $participant = $this->access->participantFor($request, $call);

        abort_if($participant === null || ! $participant->isInside(), 403, 'You are not in this meeting.');

        $participant->setRelation('call', $call);

        abort_if($ability === 'host' && ! $participant->isHost(), 403, 'Only a host can do that.');

        $request->attributes->set(CallAccess::ATTRIBUTE, $participant);

        return $next($request);
    }
}
