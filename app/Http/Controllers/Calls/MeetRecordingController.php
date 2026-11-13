<?php

namespace App\Http\Controllers\Calls;

use App\Enums\Calls\RecordingMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Calls\CallRecordingResource;
use App\Models\Calls\Call;
use App\Models\Calls\CallRecording;
use App\Services\Audit\AuditLogger;
use App\Services\Calls\CallAccess;
use App\Services\Calls\CallRecordings;
use App\Services\Calls\ParticipantAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recording controls inside the meeting. Starting and stopping only ask the recorder;
 * the recording carries on whether or not the person who started it stays.
 */
class MeetRecordingController extends Controller
{
    public function __construct(protected CallAccess $access, protected CallRecordings $recordings) {}

    public function store(Request $request, Call $call): JsonResponse
    {
        $me = $this->access->current($request);

        abort_unless(ParticipantAbilities::for($me)->record(), 403, 'You are not allowed to record this meeting.');

        $data = $request->validate(['mode' => ['nullable', Rule::enum(RecordingMode::class)]]);

        $recording = $this->recordings->start($call, $me, RecordingMode::from($data['mode'] ?? RecordingMode::Full->value));

        return response()->json(['recording' => CallRecordingResource::make($recording->load('startedBy'))->resolve()], 201);
    }

    public function stop(Request $request, Call $call, CallRecording $recording): JsonResponse
    {
        $me = $this->access->current($request);

        abort_unless(ParticipantAbilities::for($me)->record(), 403, 'You are not allowed to stop the recording.');

        $recording = $this->recordings->stop($recording->setRelation('call', $call), $me);

        return response()->json(['recording' => CallRecordingResource::make($recording->load('startedBy'))->resolve()]);
    }

    /**
     * A participant acknowledges that the meeting is being recorded and chooses to stay.
     */
    public function consent(Request $request, Call $call, AuditLogger $audit): JsonResponse
    {
        $me = $this->access->current($request);

        if ($me->recording_consent_at === null) {
            $me->forceFill(['recording_consent_at' => now()])->save();
            $audit->record('call.recording_consent', $call->organization, $me->user, $call, ['participant' => $me->name, 'guest' => $me->isGuest()]);
        }

        return response()->json(['recording_consent_at' => $me->recording_consent_at?->toISOString()]);
    }
}
