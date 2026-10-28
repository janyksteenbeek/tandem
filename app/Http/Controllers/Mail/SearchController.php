<?php

namespace App\Http\Controllers\Mail;

use App\Enums\MailboxView;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationListItemResource;
use App\Models\Inbox;
use App\Services\Conversations\ConversationQuery;
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
        ]);

        $user = $request->user();
        $organization = $user->currentOrganization;

        $inboxes = Inbox::query()->accessibleBy($user)
            ->where(fn ($q) => $q->whereNull('organization_id')->when($organization, fn ($w) => $w->orWhere('organization_id', $organization->id)))
            ->when($data['inbox'] ?? null, fn ($q, $uuid) => $uuid === 'all' ? $q->whereNull('organization_id') : $q->where('uuid', $uuid))
            ->get();

        $results = $conversations->build($inboxes, MailboxView::All, $user, ['q' => $data['q']])
            ->limit($data['limit'] ?? 25)
            ->get();

        return response()->json(['conversations' => ConversationListItemResource::collection($results)->resolve()]);
    }
}
