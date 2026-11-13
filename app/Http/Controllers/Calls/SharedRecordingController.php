<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Models\Calls\CallRecording;
use App\Services\Calls\CallRecordings;
use App\Services\Calls\RecordingPlayback;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Watching a recording without an account: through the secret link a member copied, or
 * through the signed link in a transcript email. Both show one recording and nothing else of
 * the organization.
 */
class SharedRecordingController extends Controller
{
    public function __construct(protected RecordingPlayback $playback) {}

    public function shared(string $token, CallRecordings $recordings): InertiaResponse
    {
        $recording = $recordings->findShared($token);

        abort_if($recording === null, 404);

        return $this->page($recording);
    }

    public function signed(CallRecording $recording): InertiaResponse
    {
        abort_unless($recording->status->isPlayable(), 404);

        return $this->page($recording);
    }

    public function media(Request $request, CallRecording $recording, string $file): Response
    {
        return $this->playback->stream($request, $recording, $file);
    }

    protected function page(CallRecording $recording): InertiaResponse
    {
        return Inertia::render('meet/Recording', [
            'recording' => $this->playback->describe($recording, $this->playback->signedUrls($recording)),
            'organization' => $recording->call->organization->name,
        ]);
    }
}
