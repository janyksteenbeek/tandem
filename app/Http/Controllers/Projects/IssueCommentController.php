<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Resources\Projects\IssueCommentResource;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueComment;
use App\Services\Projects\IssueCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IssueCommentController extends Controller
{
    public function __construct(protected IssueCommentService $comments) {}

    public function store(Request $request, Organization $currentOrganization, Issue $issue): JsonResponse
    {
        $this->authorize('comment', $issue);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:50000'],
            'mentions' => ['nullable', 'array', 'max:30'],
            'mentions.*' => ['integer'],
            'parent_uuid' => ['nullable', 'string', 'uuid'],
        ]);

        $parent = isset($data['parent_uuid']) ? $issue->comments()->where('uuid', $data['parent_uuid'])->first() : null;
        $comment = $this->comments->post($issue, $request->user(), $data['body'], array_map('intval', $data['mentions'] ?? []), $parent);

        return response()->json(['comment' => IssueCommentResource::make($comment)->resolve()], 201);
    }

    public function update(Request $request, Organization $currentOrganization, IssueComment $comment): JsonResponse
    {
        $this->authorize('update', $comment);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:50000'],
            'mentions' => ['nullable', 'array', 'max:30'],
            'mentions.*' => ['integer'],
        ]);

        $comment = $this->comments->update($comment, $data['body'], array_map('intval', $data['mentions'] ?? []));

        return response()->json(['comment' => IssueCommentResource::make($comment)->resolve()]);
    }

    public function react(Request $request, Organization $currentOrganization, IssueComment $comment): JsonResponse
    {
        $this->authorize('view', $comment->issue);

        $data = $request->validate(['emoji' => ['required', 'string', 'max:32'], 'on' => ['required', 'boolean']]);
        $comment = $this->comments->react($comment, $request->user(), $data['emoji'], (bool) $data['on']);

        return response()->json(['comment' => IssueCommentResource::make($comment)->resolve()]);
    }

    public function destroy(Request $request, Organization $currentOrganization, IssueComment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);
        $this->comments->delete($comment);

        return response()->json(['deleted' => true]);
    }
}
