<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Models\Calls\Call;
use App\Models\Calls\CallTranscriptDelivery;
use App\Models\Organization;
use App\Services\Calls\CallTranscripts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CallTranscriptController extends Controller
{
    public function __construct(protected CallTranscripts $transcripts) {}

    /**
     * The transcript as plain text: shown for copying, or sent as a file with `download`.
     */
    public function show(Request $request, Organization $currentOrganization, Call $call): Response|JsonResponse
    {
        $this->authorize('view', $call);

        $text = $this->transcripts->text(
            $call,
            $request->boolean('files', true),
            $request->boolean('recording', true),
        );

        if ($request->boolean('download')) {
            return response($text, 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.Str::slug($call->displayTitle()).'-chat.txt"',
            ]);
        }

        return response()->json(['text' => $text]);
    }

    /**
     * Email Meeting Chat.
     */
    public function email(Request $request, Organization $currentOrganization, Call $call): JsonResponse
    {
        $this->authorize('share', $call);

        $data = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
            'recipients.*.email' => ['required', 'email:rfc', 'max:254'],
            'recipients.*.name' => ['nullable', 'string', 'max:120'],
            'include_files' => ['sometimes', 'boolean'],
            'include_recording' => ['sometimes', 'boolean'],
        ]);

        $deliveries = $this->transcripts->email(
            $call,
            $request->user(),
            $data['recipients'],
            (bool) ($data['include_files'] ?? true),
            (bool) ($data['include_recording'] ?? true),
        );

        return response()->json(['queued' => $deliveries->count()], 202);
    }

    public function resend(Request $request, Organization $currentOrganization, Call $call, CallTranscriptDelivery $transcriptDelivery): JsonResponse
    {
        $this->authorize('share', $call);

        $this->transcripts->resend($transcriptDelivery);

        return response()->json(['queued' => 1], 202);
    }
}
