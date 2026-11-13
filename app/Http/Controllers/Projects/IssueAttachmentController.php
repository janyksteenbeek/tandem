<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Resources\Projects\IssueAttachmentResource;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueAttachment;
use App\Services\Projects\IssueAttachments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Issue files are never served from a public URL: every download goes through `show`, which
 * checks the issue policy and sends the same hardening headers as mail attachments.
 */
class IssueAttachmentController extends Controller
{
    public function __construct(protected IssueAttachments $attachments) {}

    public function store(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('attach', $issue);

        $request->validate(['file' => ['required', 'file', 'max:'.(int) (config('tandem.attachments.max_upload_bytes', 25 * 1024 * 1024) / 1024)]]);

        $attachment = $this->attachments->store($issue, $request->file('file'), $request->user());

        return response()->json(['attachment' => IssueAttachmentResource::make($attachment->load('uploadedBy'))->resolve()], 201);
    }

    public function show(Request $request, IssueAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        $mime = $attachment->mime_type;

        if (preg_match('#^(text/html|image/svg|application/(x-)?javascript|text/javascript)#i', $mime)) {
            $mime = 'application/octet-stream';
        }

        $inline = ! $request->boolean('download') && preg_match('#^(image/(?!svg)|application/pdf|text/plain|text/csv|audio/mpeg|video/mp4)#i', $mime);
        $filename = str_replace(['"', "\r", "\n"], '', $attachment->filename);

        return Storage::disk($attachment->disk)->response($attachment->path, $filename, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, Organization $currentOrganization, IssueAttachment $attachment): JsonResponse
    {
        $this->authorize('delete', $attachment);
        $this->attachments->delete($attachment, $request->user());

        return response()->json(['deleted' => true]);
    }
}
