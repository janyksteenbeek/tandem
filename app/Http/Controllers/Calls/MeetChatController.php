<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Http\Resources\Calls\CallMessageResource;
use App\Models\Calls\Call;
use App\Models\Calls\CallMessage;
use App\Services\Calls\CallAccess;
use App\Services\Calls\CallChat;
use App\Services\Calls\ParticipantAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetChatController extends Controller
{
    public function __construct(protected CallAccess $access, protected CallChat $chat) {}

    /**
     * Messages in order. `after` returns only what a reconnecting browser missed; without it
     * the latest page is returned and `before` walks back through history.
     */
    public function index(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $call->messages()->withTrashed()->with(['participant.user', 'reactions.participant', 'files.call', 'parent.participant']);

        if (isset($data['after'])) {
            $messages = $query->where('id', '>', $data['after'])->orderBy('id')->limit(500)->get();
        } else {
            $messages = $query
                ->when(isset($data['before']), fn ($q) => $q->where('id', '<', $data['before']))
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->reverse()
                ->values();
        }

        return response()->json([
            'messages' => CallMessageResource::collection($messages)->resolve(),
            'has_more' => ! isset($data['after']) && $messages->count() === 100,
        ]);
    }

    public function store(Request $request, Call $call): JsonResponse
    {
        $me = $this->access->current($request);

        abort_unless(ParticipantAbilities::for($me)->chat(), 403, 'Chat is closed for this meeting.');

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.CallChat::MAX_LENGTH],
            'client_id' => ['nullable', 'string', 'max:40'],
            'parent_uuid' => ['nullable', 'uuid'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['uuid'],
        ]);

        $parent = isset($data['parent_uuid']) ? $call->messages()->where('uuid', $data['parent_uuid'])->first() : null;
        $message = $this->chat->post($call, $me, $data['body'] ?? null, $data['client_id'] ?? null, $parent, $data['files'] ?? []);

        return response()->json(['message' => CallMessageResource::make($message)->resolve()], 201);
    }

    public function update(Request $request, Call $call, CallMessage $message): JsonResponse
    {
        $me = $this->access->current($request);

        abort_unless($this->chat->canEdit($message, $me) && ParticipantAbilities::for($me)->chat(), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:'.CallChat::MAX_LENGTH]]);

        return response()->json(['message' => CallMessageResource::make($this->chat->update($message, $data['body']))->resolve()]);
    }

    public function destroy(Request $request, Call $call, CallMessage $message): JsonResponse
    {
        abort_unless($this->chat->canDelete($message, $this->access->current($request)), 403);

        $this->chat->delete($message);

        return response()->json(['deleted' => true]);
    }

    public function react(Request $request, Call $call, CallMessage $message): JsonResponse
    {
        $me = $this->access->current($request);

        abort_unless(ParticipantAbilities::for($me)->chat(), 403);

        $data = $request->validate(['emoji' => ['required', 'string', 'max:32'], 'on' => ['required', 'boolean']]);

        return response()->json(['message' => CallMessageResource::make($this->chat->react($message, $me, $data['emoji'], (bool) $data['on']))->resolve()]);
    }

    public function read(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate(['message_id' => ['required', 'integer', 'min:1']]);

        $this->chat->markRead($this->access->current($request), $data['message_id']);

        return response()->json(['read' => true]);
    }
}
