<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Conversation;
use App\Services\Conversations\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(protected CommentService $comments) {}

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('comment', $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'mentions' => ['array'],
            'mentions.*' => ['integer'],
        ]);

        $comment = $this->comments->post($conversation, $request->user(), $data['body'], $data['mentions'] ?? []);

        return response()->json(['comment' => CommentResource::make($comment->load('user'))->resolve()], 201);
    }

    public function update(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('update', $comment);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'mentions' => ['array'],
            'mentions.*' => ['integer'],
        ]);

        $comment = $this->comments->update($comment, $data['body'], $data['mentions'] ?? []);

        return response()->json(['comment' => CommentResource::make($comment->load('user'))->resolve()]);
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);

        $this->comments->delete($comment);

        return response()->json(['deleted' => true]);
    }
}
