<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Draft;
use App\Models\DraftAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DraftAttachmentController extends Controller
{
    public function store(Request $request, Draft $draft): JsonResponse
    {
        $this->authorize('update', $draft);

        $maxKilobytes = (int) (config('tandem.attachments.max_upload_bytes') / 1024);

        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKilobytes],
            'inline' => ['boolean'],
        ]);

        $file = $request->file('file');
        $extension = Str::lower($file->getClientOriginalExtension());

        abort_if(in_array($extension, config('tandem.attachments.blocked_extensions', []), true), 422, 'That file type is not allowed.');

        $disk = config('tandem.attachments.disk', 'local');
        $path = $file->storeAs('drafts/'.$draft->uuid, Str::random(12).($extension ? '.'.$extension : ''), $disk);
        $inline = $request->boolean('inline') && Str::startsWith((string) $file->getMimeType(), 'image/');

        $attachment = $draft->attachments()->create([
            'filename' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'disk' => $disk,
            'path' => $path,
            'content_id' => $inline ? Str::uuid().'@tandem' : null,
            'is_inline' => $inline,
        ]);

        return response()->json([
            'attachment' => [
                'uuid' => $attachment->uuid,
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'is_inline' => $attachment->is_inline,
                'content_id' => $attachment->content_id,
                'url' => $inline ? route('drafts.attachments.show', [$draft, $attachment]) : null,
            ],
        ], 201);
    }

    public function show(Request $request, Draft $draft, DraftAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $draft);
        abort_unless($attachment->draft_id === $draft->id, 404);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->filename, ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }

    public function destroy(Request $request, Draft $draft, DraftAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $draft);
        abort_unless($attachment->draft_id === $draft->id, 404);

        $attachment->delete();

        return response()->json(['deleted' => true]);
    }
}
