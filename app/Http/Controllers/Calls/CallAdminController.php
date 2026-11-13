<?php

namespace App\Http\Controllers\Calls;

use App\Enums\Calls\RecordingStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Calls\Call;
use App\Models\Calls\CallRecording;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use App\Services\Calls\Media\MediaServer;
use App\Services\Calls\Media\MediaServerException;
use App\Services\Calls\Media\Recorder;
use App\Services\Calls\RecordingExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Organization-wide call settings and a health view of the media stack, for administrators.
 */
class CallAdminController extends Controller
{
    public function settings(Request $request, Organization $currentOrganization, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manage', $currentOrganization);

        $data = $request->validate([
            'waiting_room' => ['required', 'boolean'],
            'screen_share' => ['required', Rule::in(['everyone', 'hosts'])],
            'recording' => ['required', Rule::in(['hosts', 'members'])],
            'guest_uploads' => ['required', 'boolean'],
            'auto_email_transcript' => ['required', 'boolean'],
            'recording_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $currentOrganization->forceFill(['call_settings' => [
            'defaults' => array_intersect_key($data, Call::DEFAULT_SETTINGS),
            'recording_retention_days' => $data['recording_retention_days'] ?? null,
        ]])->save();

        $audit->record('organization.call_settings_updated', $currentOrganization, $request->user(), $currentOrganization, $data);

        return back();
    }

    /**
     * Live state of the media server, the recorder and recording storage.
     */
    public function diagnostics(Request $request, Organization $currentOrganization, MediaServer $media, Recorder $recorder, RecordingExporter $exporter): JsonResponse
    {
        $this->authorize('manage', $currentOrganization);

        $rooms = [];
        $mediaError = null;

        if ($media->isConfigured()) {
            try {
                $names = Call::query()->where('organization_id', $currentOrganization->id)->whereIn('status', ['live', 'pending'])->get()->keyBy(fn (Call $call) => $call->roomName());

                foreach ($media->listRooms() as $room) {
                    $call = $names->get((string) ($room['name'] ?? ''));

                    // Rooms of other organizations on a shared media server are counted, never named.
                    $rooms[] = [
                        'title' => $call?->displayTitle(),
                        'code' => $call?->code,
                        'participants' => (int) ($room['num_participants'] ?? $room['numParticipants'] ?? 0),
                        'publishers' => (int) ($room['num_publishers'] ?? $room['numPublishers'] ?? 0),
                        'recording' => $call?->activeRecording()->exists() ?? false,
                    ];
                }
            } catch (MediaServerException $exception) {
                $mediaError = $exception->getMessage();
            }
        }

        $disk = (string) config('calls.recordings.disk', 'local');
        $storage = null;

        if (config('filesystems.disks.'.$disk.'.driver') === 'local') {
            $root = Storage::disk($disk)->path('');
            $storage = ['free_bytes' => (int) @disk_free_space($root), 'total_bytes' => (int) @disk_total_space($root)];
        }

        $recordings = CallRecording::query()->whereIn('call_id', Call::query()->where('organization_id', $currentOrganization->id)->select('id'));

        return response()->json([
            'media' => [
                'configured' => $media->isConfigured(),
                'reachable' => $media->isConfigured() && $mediaError === null,
                'error' => $mediaError,
                'public_url' => config('calls.livekit.public_url'),
                'rooms' => $rooms,
            ],
            'recorder' => [
                'configured' => $recorder->isConfigured(),
                'stats' => $recorder->isConfigured() ? $recorder->stats() : null,
            ],
            'storage' => $storage,
            'exports' => ['ffmpeg' => $exporter->isAvailable()],
            'recordings' => [
                'active' => (clone $recordings)->whereIn('status', [RecordingStatus::Starting->value, RecordingStatus::Recording->value])->count(),
                'processing' => (clone $recordings)->where('status', RecordingStatus::Processing->value)->count(),
                'failed_last_week' => (clone $recordings)->where('status', RecordingStatus::Failed->value)->where('created_at', '>', now()->subWeek())->count(),
                'stored_bytes' => (int) (clone $recordings)->sum('size_bytes'),
            ],
            'recent_errors' => AuditLog::query()
                ->where('organization_id', $currentOrganization->id)
                ->whereIn('action', ['call.recording_failed'])
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (AuditLog $log) => ['action' => $log->action, 'reason' => $log->payload['reason'] ?? null, 'at' => $log->created_at?->toISOString()]),
        ]);
    }
}
