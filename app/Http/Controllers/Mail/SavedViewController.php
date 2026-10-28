<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Inbox;
use App\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedViewController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:32'],
            'filters' => ['required', 'array'],
            'inbox' => ['nullable', 'string', 'exists:inboxes,uuid'],
            'is_shared' => ['boolean'],
        ]);

        $user = $request->user();
        $inbox = isset($data['inbox']) ? Inbox::query()->where('uuid', $data['inbox'])->firstOrFail() : null;

        if ($inbox) {
            $this->authorize('view', $inbox);
        }

        $shared = (bool) ($data['is_shared'] ?? false) && $inbox?->organization_id && $user->can('manage', $inbox);

        $view = SavedView::create([
            'organization_id' => $shared ? $inbox->organization_id : null,
            'user_id' => $shared ? null : $user->id,
            'inbox_id' => $inbox?->id,
            'name' => $data['name'],
            'icon' => $data['icon'] ?? 'filter',
            'filters' => $data['filters'],
            'is_shared' => $shared,
            'position' => SavedView::query()->where('user_id', $user->id)->max('position') + 1,
        ]);

        return response()->json(['view' => $view], 201);
    }

    public function destroy(Request $request, SavedView $savedView): JsonResponse
    {
        $user = $request->user();

        abort_unless($savedView->user_id === $user->id || ($savedView->inbox && $user->can('manage', $savedView->inbox)), 403);

        $savedView->delete();

        return response()->json(['deleted' => true]);
    }
}
