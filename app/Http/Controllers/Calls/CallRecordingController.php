<?php

namespace App\Http\Controllers\Calls;

use App\Enums\Calls\ExportKind;
use App\Enums\Calls\ExportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Calls\BuildRecordingExport;
use App\Models\Calls\Call;
use App\Models\Calls\CallRecording;
use App\Models\Calls\CallRecordingExport;
use App\Models\Organization;
use App\Services\Calls\CallRecordings;
use App\Services\Calls\RecordingExporter;
use App\Services\Calls\RecordingPlayback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recordings as members of the organization use them: playback, downloads, exports, the
 * secret share link and deletion. Every route checks the call policy.
 */
class CallRecordingController extends Controller
{
    public function __construct(protected CallRecordings $recordings, protected RecordingPlayback $playback) {}

    public function show(Request $request, Organization $currentOrganization, Call $call, CallRecording $recording): InertiaResponse
    {
        $this->authorize('view', $call);

        $recording->setRelation('call', $call);

        return Inertia::render('calls/Recording', [
            'call' => ['uuid' => $call->uuid, 'title' => $call->displayTitle()],
            'recording' => [
                ...$this->playback->describe($recording, fn (string $file) => route('calls.recordings.media', [
                    'current_organization' => $currentOrganization->slug,
                    'call' => $call->uuid,
                    'recording' => $recording->uuid,
                    'file' => $file,
                ])),
                'has_share_link' => $recording->isShareLinkValid(),
                'exports' => $recording->exports()->latest('id')->get()->map(fn (CallRecordingExport $export) => CallController::export($export))->values(),
            ],
            'exportsAvailable' => Cache::remember('calls.ffmpeg-available', 600, fn () => app(RecordingExporter::class)->isAvailable()),
            'can' => [
                'share' => $request->user()->can('share', $call),
                'delete' => $request->user()->can('deleteRecordings', $call),
            ],
        ]);
    }

    public function media(Request $request, Organization $currentOrganization, Call $call, CallRecording $recording, string $file): Response
    {
        $this->authorize('view', $call);

        return $this->playback->stream($request, $recording, $file);
    }

    /**
     * Ask for a single-file version. An existing export of that kind is reused; a failed one
     * is retried.
     */
    public function export(Request $request, Organization $currentOrganization, Call $call, CallRecording $recording): JsonResponse
    {
        $this->authorize('share', $call);

        abort_unless($recording->status->isPlayable(), 422, 'This recording is not ready yet.');

        $data = $request->validate(['kind' => ['required', Rule::enum(ExportKind::class)]]);

        $export = $recording->exports()->where('kind', $data['kind'])->latest('id')->first();

        if ($export === null || $export->status === ExportStatus::Failed) {
            $export?->delete();
            $export = $recording->exports()->create([
                'kind' => $data['kind'],
                'requested_by_id' => $request->user()->id,
                'status' => ExportStatus::Queued,
                'disk' => $recording->disk,
            ]);

            BuildRecordingExport::dispatch($export);
        }

        return response()->json(['export' => CallController::export($export)], 202);
    }

    public function exportStatus(Organization $currentOrganization, Call $call, CallRecording $recording, CallRecordingExport $export): JsonResponse
    {
        $this->authorize('view', $call);

        return response()->json(['export' => CallController::export($export)]);
    }

    public function download(Request $request, Organization $currentOrganization, Call $call, CallRecording $recording, CallRecordingExport $export): Response
    {
        $this->authorize('view', $call);

        abort_unless($export->status === ExportStatus::Ready, 404);

        return $this->playback->download($request, $export);
    }

    /**
     * Create (or replace) the secret link for people without an account.
     */
    public function share(Request $request, Organization $currentOrganization, Call $call, CallRecording $recording): JsonResponse
    {
        $this->authorize('share', $call);

        abort_unless($recording->status->isPlayable(), 422, 'This recording is not ready yet.');

        return response()->json([
            'url' => $this->recordings->share($recording->setRelation('call', $call), $request->user()),
            'expires_at' => $recording->share_expires_at?->toISOString(),
        ]);
    }

    public function unshare(Request $request, Organization $currentOrganization, Call $call, CallRecording $recording): JsonResponse
    {
        $this->authorize('share', $call);

        $this->recordings->revokeShare($recording->setRelation('call', $call), $request->user());

        return response()->json(['revoked' => true]);
    }

    public function destroy(Request $request, Organization $currentOrganization, Call $call, CallRecording $recording): RedirectResponse
    {
        $this->authorize('deleteRecordings', $call);

        $this->recordings->delete($recording->setRelation('call', $call), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Recording deleted.']);

        return to_route('calls.show', ['current_organization' => $currentOrganization->slug, 'call' => $call->uuid]);
    }
}
