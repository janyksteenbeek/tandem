<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Resources\Projects\IssueResource;
use App\Models\Mail\Attachment;
use App\Models\Mail\Conversation;
use App\Models\Mail\Message;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Services\Projects\ConversationIssueLinks;
use App\Services\Projects\IssueQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The issue side of the bridge. The viewer must be able to open the conversation themselves;
 * linking never grants mail access to anyone else.
 */
class IssueConversationLinkController extends Controller
{
    public function __construct(protected ConversationIssueLinks $links) {}

    public function index(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('view', $issue);

        return response()->json($this->links->visibleLinksFor($issue, $request->user()));
    }

    public function store(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('linkConversation', $issue);

        $data = $request->validate(['conversation_uuid' => ['required', 'string', 'uuid'], 'note' => ['nullable', 'string', 'max:500']]);
        $conversation = Conversation::query()->where('uuid', $data['conversation_uuid'])->firstOrFail();
        $this->authorize('view', $conversation);

        $this->links->link($issue, $conversation, $request->user(), $data['note'] ?? null);

        return $this->respond($issue, $request);
    }

    public function destroy(Request $request, Organization $currentOrganization, Issue $issue, Conversation $conversation): JsonResponse
    {
        $this->authorize('linkConversation', $issue);
        $this->authorize('view', $conversation);

        $this->links->unlink($issue, $conversation, $request->user());

        return $this->respond($issue, $request);
    }

    public function shareExcerpt(Request $request, Organization $currentOrganization, Issue $issue, Conversation $conversation): JsonResponse
    {
        $this->authorize('shareContent', $issue);
        $this->authorize('view', $conversation);

        $data = $request->validate(['message_uuid' => ['required', 'string', 'uuid'], 'selection_html' => ['nullable', 'string', 'max:200000']]);
        $message = Message::query()->where('uuid', $data['message_uuid'])->where('conversation_id', $conversation->id)->firstOrFail();

        $this->links->shareExcerpt($issue, $message, $request->user(), $data['selection_html'] ?? null);

        return $this->respond($issue, $request);
    }

    public function shareAttachment(Request $request, Organization $currentOrganization, Issue $issue, Conversation $conversation): JsonResponse
    {
        $this->authorize('shareContent', $issue);
        $this->authorize('view', $conversation);

        $data = $request->validate(['attachment_uuid' => ['required', 'string', 'uuid']]);
        $attachment = Attachment::query()->where('uuid', $data['attachment_uuid'])->whereHas('message', fn ($q) => $q->where('conversation_id', $conversation->id))->firstOrFail();

        $this->links->shareAttachment($issue, $attachment, $request->user());

        return $this->respond($issue, $request);
    }

    protected function respond(Issue $issue, Request $request): JsonResponse
    {
        return response()->json([
            'issue' => IssueResource::make($issue->fresh(IssueQuery::EAGER))->resolve(),
            'linked_conversations' => $this->links->visibleLinksFor($issue, $request->user()),
        ]);
    }
}
