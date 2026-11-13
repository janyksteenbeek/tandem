<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Models\Calls\Call;
use App\Models\Calls\CallLink;
use App\Models\Calls\CallRecording;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Services\Calls\CallLinks;
use App\Services\Projects\IssueCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Connects a call to an issue or project, and posts a call's outcome into a linked issue.
 */
class CallLinkController extends Controller
{
    public function __construct(protected CallLinks $links) {}

    public function store(Request $request, Organization $currentOrganization, Call $call): JsonResponse
    {
        $this->authorize('update', $call);

        $data = $request->validate([
            'type' => ['required', Rule::in(['issue', 'project'])],
            'key' => ['required', 'string', 'max:120'],
        ]);

        $linkable = $this->links->resolve($currentOrganization, $request->user(), $data['type'], $data['key']);

        abort_if($linkable === null, 404, 'That '.$data['type'].' was not found.');

        $this->links->link($call, $linkable, $request->user());

        return response()->json(['links' => $this->links->describe($call, $request->user())], 201);
    }

    public function destroy(Request $request, Organization $currentOrganization, Call $call, CallLink $link): JsonResponse
    {
        $this->authorize('update', $call);

        $link->delete();

        return response()->json(['links' => $this->links->describe($call, $request->user())]);
    }

    /**
     * Leave a comment on a linked issue that points back at the meeting: its chat and, when
     * asked, its recordings. The links only work for people who may open the call.
     */
    public function comment(Request $request, Organization $currentOrganization, Call $call, CallLink $link, IssueCommentService $comments): JsonResponse
    {
        $this->authorize('share', $call);

        $issue = $link->linkable;

        abort_unless($issue instanceof Issue, 422, 'Only issues can receive a meeting summary.');
        $this->authorize('comment', $issue);

        $data = $request->validate([
            'include_recordings' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $callUrl = route('calls.show', ['current_organization' => $currentOrganization->slug, 'call' => $call->uuid]);
        $html = '<p><strong>Meeting:</strong> <a href="'.e($callUrl).'">'.e($call->displayTitle()).'</a>';

        if ($call->started_at) {
            $html .= ' · '.e($call->started_at->setTimezone($currentOrganization->timezone ?: config('app.timezone'))->format('j M Y, H:i'));
        }

        $html .= '</p>';

        if (filled($data['note'] ?? null)) {
            $html .= '<p>'.nl2br(e((string) $data['note'])).'</p>';
        }

        $items = [];

        if ($call->message_count > 0) {
            $items[] = '<li><a href="'.e($callUrl).'#chat">Meeting chat</a> ('.$call->message_count.' '.($call->message_count === 1 ? 'message' : 'messages').')</li>';
        }

        if ($data['include_recordings'] ?? true) {
            $call->recordings()->whereIn('status', ['ready', 'partial'])->orderBy('id')->get()->each(function (CallRecording $recording) use (&$items, $currentOrganization, $call) {
                $url = route('calls.recordings.show', ['current_organization' => $currentOrganization->slug, 'call' => $call->uuid, 'recording' => $recording->uuid]);
                $items[] = '<li><a href="'.e($url).'">Recording</a> ('.e($recording->mode->label()).', '.(int) round($recording->duration_ms / 60000).' min)</li>';
            });
        }

        if ($items !== []) {
            $html .= '<ul>'.implode('', $items).'</ul>';
        }

        $comments->post($issue, $request->user(), $html);

        return response()->json(['posted' => true], 201);
    }
}
