<?php

namespace App\Http\Controllers\Calls\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Calls\CallRecording;
use App\Services\Calls\CallRecordings;
use App\Services\Mail\Providers\Forwarding\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Reports from the recorder: started, progress, warnings, finished, failed. Signed
 * with the shared recorder secret using the same timestamped HMAC as the other Tandem webhooks.
 */
class RecorderWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, CallRecordings $recordings): JsonResponse
    {
        $secret = (string) config('calls.recorder.secret');

        if ($secret === '' || ! $signature->verify($secret, $request->getContent(), $request->header(WebhookSignature::HEADER))) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! is_string($payload['event'] ?? null) || ! is_string($payload['id'] ?? null)) {
            return response()->json(['status' => 'invalid'], 422);
        }

        $recording = CallRecording::query()->where('uuid', $payload['id'])->with('call.organization')->first();

        if ($recording === null) {
            Log::warning('calls.recorder_event_for_unknown_recording', ['id' => $payload['id'], 'event' => $payload['event']]);

            // Acknowledge so the recorder stops retrying: the recording was deleted on our side.
            return response()->json(['status' => 'unknown'], 202);
        }

        $recordings->handleRecorderEvent($recording, $payload['event'], $payload);

        return response()->json(['status' => 'ok']);
    }
}
