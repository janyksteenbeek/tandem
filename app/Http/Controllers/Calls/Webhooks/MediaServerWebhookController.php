<?php

namespace App\Http\Controllers\Calls\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Services\Calls\CallActions;
use App\Services\Calls\Media\AccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Room events from the media server: who connected, who dropped, and when a room closed.
 * The server signs each delivery with our key pair: the Authorization header is a token whose
 * `sha256` claim is the hash of the body.
 */
class MediaServerWebhookController extends Controller
{
    public function __invoke(Request $request, AccessToken $tokens, CallActions $actions): JsonResponse
    {
        $body = $request->getContent();
        $claims = $tokens->verify(Str::after((string) $request->header('Authorization'), 'Bearer '));

        if ($claims === null || ! hash_equals((string) ($claims['sha256'] ?? ''), base64_encode(hash('sha256', $body, true)))) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $event = json_decode($body, true);

        if (! is_array($event)) {
            return response()->json(['status' => 'ignored'], 202);
        }

        $room = (string) ($event['room']['name'] ?? '');
        $call = Str::startsWith($room, 'call-') ? Call::query()->where('uuid', Str::after($room, 'call-'))->first() : null;

        if ($call === null) {
            return response()->json(['status' => 'ignored'], 202);
        }

        $identity = (string) ($event['participant']['identity'] ?? '');
        $participant = Str::startsWith($identity, 'p_')
            ? $call->participants()->where('uuid', Str::after($identity, 'p_'))->first()
            : null;

        match ($event['event'] ?? null) {
            'participant_joined' => $this->whenInside($participant, $call, fn (CallParticipant $p) => $actions->markConnected($p)),
            'participant_left' => $this->whenInside($participant, $call, fn (CallParticipant $p) => $actions->markDisconnected($p)),
            'room_finished' => $actions->end($call, null, 'room_closed'),
            default => null,
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  callable(CallParticipant): void  $callback
     */
    protected function whenInside(?CallParticipant $participant, Call $call, callable $callback): void
    {
        if ($participant !== null && $participant->isInside()) {
            $callback($participant->setRelation('call', $call));
        }
    }
}
