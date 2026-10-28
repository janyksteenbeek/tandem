<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\Mail\Ingestion\AttachmentStore;
use App\Services\Mail\MailProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Stream an attachment to an authorized viewer. Attachments are never served from a public URL.
     */
    public function show(Request $request, Attachment $attachment, MailProviderManager $providers, AttachmentStore $store): StreamedResponse
    {
        $message = $attachment->message;
        $this->authorize('view', $message->conversation);

        if (! $attachment->isStored()) {
            $account = $message->mailAccount;
            abort_unless($account !== null, 404, 'Attachment content is not available.');

            $store->put($attachment, $providers->for($account)->fetchAttachment($account, $attachment));
            $attachment->refresh();
        }

        $disposition = $request->boolean('download') || ! $this->isPreviewable($attachment->mime_type) ? 'attachment' : 'inline';
        $filename = str_replace(['"', "\r", "\n"], '', $attachment->filename);

        return Storage::disk($attachment->disk)->response((string) $attachment->path, $filename, [
            'Content-Type' => $this->safeContentType($attachment->mime_type),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ], $disposition);
    }

    protected function isPreviewable(string $mime): bool
    {
        return str_starts_with($mime, 'image/') || in_array($mime, ['application/pdf', 'text/plain', 'text/csv', 'audio/mpeg', 'video/mp4'], true);
    }

    protected function safeContentType(string $mime): string
    {
        return in_array($mime, ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/javascript', 'text/javascript'], true)
            ? 'application/octet-stream'
            : $mime;
    }
}
