<?php

namespace App\Services\Calls;

use App\Http\Resources\Calls\CallRecordingResource;
use App\Models\Calls\CallRecording;
use App\Models\Calls\CallRecordingExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the recording player needs: the tracks on their shared timeline, each with a URL the
 * viewer is allowed to stream. Members get URLs behind the call policy; anyone watching
 * through a shared or emailed link gets signed URLs that expire on their own.
 */
class RecordingPlayback
{
    public function __construct(protected CallRecordings $recordings) {}

    /**
     * @param  callable(string): string  $urlFor  Maps a manifest file name to a stream URL.
     * @return array<string, mixed>
     */
    public function describe(CallRecording $recording, callable $urlFor): array
    {
        $manifest = $recording->manifest ?? [];

        return [
            ...CallRecordingResource::make($recording->loadMissing('startedBy'))->resolve(),
            'title' => $recording->call->displayTitle(),
            'tracks' => array_map(fn (array $track) => [
                'file' => (string) ($track['file'] ?? ''),
                'kind' => (string) ($track['kind'] ?? 'audio'),
                'source' => (string) ($track['source'] ?? ''),
                'codec' => (string) ($track['codec'] ?? ''),
                'participant' => [
                    'identity' => (string) ($track['participant']['identity'] ?? ''),
                    'name' => (string) ($track['participant']['name'] ?? 'Participant'),
                ],
                'start_ms' => (int) ($track['start_ms'] ?? 0),
                'duration_ms' => (int) ($track['duration_ms'] ?? 0),
                'width' => (int) ($track['width'] ?? 0),
                'height' => (int) ($track['height'] ?? 0),
                'size_bytes' => (int) ($track['size_bytes'] ?? 0),
                'url' => $urlFor((string) ($track['file'] ?? '')),
            ], $recording->tracks()),
            'events' => array_values(array_filter((array) ($manifest['events'] ?? []), 'is_array')),
        ];
    }

    /**
     * Signed, expiring stream URLs for a viewer without an account.
     *
     * @return callable(string): string
     */
    public function signedUrls(CallRecording $recording, int $hours = 12): callable
    {
        return fn (string $file) => URL::temporarySignedRoute('meet.recordings.media', now()->addHours($hours), [
            'recording' => $recording->uuid,
            'file' => $file,
        ]);
    }

    /**
     * Stream one track file. Only names listed in the manifest are ever opened, and the
     * response supports range requests so the player can seek.
     */
    public function stream(Request $request, CallRecording $recording, string $file): Response
    {
        $track = $recording->track($file);

        abort_if($track === null || ! $recording->status->isPlayable(), 404);

        return $this->respond($request, $recording->disk, $this->recordings->directory($recording).'/'.$file, $file, ($track['kind'] ?? 'video') === 'audio' ? 'audio/webm' : 'video/webm');
    }

    public function download(Request $request, CallRecordingExport $export): Response
    {
        abort_if($export->path === null, 404);

        $mime = match ($export->kind->extension()) {
            'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4',
            default => 'video/webm',
        };

        return $this->respond($request, $export->disk, $export->path, (string) $export->filename, $mime, download: true);
    }

    protected function respond(Request $request, string $diskName, string $path, string $filename, string $mime, bool $download = false): Response
    {
        $disk = Storage::disk($diskName);

        abort_unless($disk->exists($path), 404);

        $download = $download || $request->boolean('download');
        $headers = [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        if (config('filesystems.disks.'.$diskName.'.driver') === 'local') {
            $response = response()->file($disk->path($path), $headers);

            if ($download) {
                $response->setContentDisposition('attachment', str_replace(['"', "\r", "\n", '/', '\\'], '', $filename));
            }

            return $response;
        }

        // Object storage: let the store serve the bytes (and the range requests) directly.
        return redirect()->away($disk->temporaryUrl($path, now()->addHours(2), $download ? ['ResponseContentDisposition' => 'attachment; filename="'.$filename.'"'] : []));
    }
}
