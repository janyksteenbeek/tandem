<?php

namespace App\Http\Controllers\Mail;

use App\Enums\Mail\MailboxView;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mail\ConversationListItemResource;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Models\Mail\Inbox;
use App\Models\Projects\Issue;
use App\Services\Mail\Conversations\ConversationQuery;
use App\Services\Projects\IssueQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Search every inbox the user can see (personal + shared in the current organization).
     */
    public function __invoke(Request $request, ConversationQuery $conversations): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'inbox' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'types' => ['nullable', 'string'],
        ]);

        $types = array_filter(explode(',', (string) ($data['types'] ?? 'conversations')));

        $user = $request->user();
        $organization = $user->currentOrganization;

        $inboxes = Inbox::query()->accessibleBy($user)
            ->where(fn ($q) => $q->whereNull('organization_id')->when($organization, fn ($w) => $w->orWhere('organization_id', $organization->id)))
            ->when($data['inbox'] ?? null, fn ($q, $uuid) => $uuid === 'all' ? $q->whereNull('organization_id') : $q->where('uuid', $uuid))
            ->get();

        $results = in_array('conversations', $types, true)
            ? $conversations->build($inboxes, MailboxView::All, $user, ['q' => $data['q']])->limit($data['limit'] ?? 25)->get()
            : collect();

        $issues = [];

        if (in_array('issues', $types, true) && $organization) {
            $issues = IssueListItemResource::collection(
                Issue::query()->visibleTo($user)->with(IssueQuery::EAGER)->search($data['q'])->orderByDesc('last_activity_at')->limit($data['limit'] ?? 10)->get()
            )->resolve();
        }

        return response()->json([
            'conversations' => in_array('conversations', $types, true) ? ConversationListItemResource::collection($results)->resolve() : [],
            'issues' => $issues,
        ]);
    }
}
