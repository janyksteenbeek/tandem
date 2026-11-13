<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Http\Resources\Calls\CallFileResource;
use App\Models\Calls\Call;
use App\Models\Calls\CallFile;
use App\Services\Calls\CallAccess;
use App\Services\Calls\CallFiles;
use App\Services\Calls\ParticipantAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files in a meeting's chat. Nothing is served from a public path: a download needs to come
 * from someone in the call, from a member who may see its history, or from a signed link that
 * was put in a transcript email.
 */
class MeetFileController extends Controller
{
    public function __construct(protected CallAccess $access, protected CallFiles $files) {}

    public function store(Request $request, Call $call): JsonResponse
    {
        $me = $this->access->current($request);

        abort_unless(ParticipantAbilities::for($me)->upload(), 403, 'You cannot share files in this meeting.');

        $request->validate(['file' => ['required', 'file', 'max:'.(int) (config('calls.files.max_upload_bytes') / 1024)]]);

        $file = $this->files->store($call, $me, $request->file('file'));

        return response()->json(['file' => CallFileResource::make($file->setRelation('call', $call))->resolve()], 201);
    }

    public function show(Request $request, Call $call, CallFile $file): StreamedResponse
    {
        $participant = $this->access->participantFor($request, $call);

        abort_unless($participant?->isInside() || $request->user()?->can('view', $call), 403);

        return $this->respond($request, $file);
    }

    /**
     * Reached through an expiring signed link in a transcript email.
     */
    public function signed(Request $request, CallFile $file): StreamedResponse
    {
        abort_if($file->message_id === null, 404);

        return $this->respond($request, $file);
    }

    public function destroy(Request $request, Call $call, CallFile $file): JsonResponse
    {
        abort_unless($this->files->discard($file, $this->access->current($request)), 403);

        return response()->json(['deleted' => true]);
    }

    protected function respond(Request $request, CallFile $file): StreamedResponse
    {
        $mime = $file->mime_type;

        if (preg_match('#^(text/html|image/svg|application/(x-)?javascript|text/javascript)#i', $mime)) {
            $mime = 'application/octet-stream';
        }

        $inline = ! $request->boolean('download') && preg_match('#^(image/(?!svg)|application/pdf|text/plain|text/csv|audio/|video/mp4|video/webm)#i', $mime);
        $filename = str_replace(['"', "\r", "\n"], '', $file->filename);

        return Storage::disk($file->disk)->response($file->path, $filename, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }
}
