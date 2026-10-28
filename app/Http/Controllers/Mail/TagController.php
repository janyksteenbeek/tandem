<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Organization;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['nullable', Rule::in(Tag::COLORS)],
            'scope' => ['required', Rule::in(['organization', 'personal'])],
        ]);

        $user = $request->user();
        $organization = $data['scope'] === 'organization' ? $user->currentOrganization : null;

        if ($organization instanceof Organization) {
            $this->authorize('view', $organization);
        }

        $tag = Tag::query()->firstOrCreate(
            [
                'organization_id' => $organization?->id,
                'user_id' => $organization ? null : $user->id,
                'slug' => Str::slug($data['name']),
            ],
            ['name' => trim($data['name']), 'color' => $data['color'] ?? collect(Tag::COLORS)->random()],
        );

        return response()->json(['tag' => TagResource::make($tag)->resolve()], 201);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $this->authorizeTag($request, $tag);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', Rule::in(Tag::COLORS)],
        ]);

        $tag->update(['name' => trim($data['name']), 'slug' => Str::slug($data['name']), 'color' => $data['color']]);

        return response()->json(['tag' => TagResource::make($tag)->resolve()]);
    }

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        $this->authorizeTag($request, $tag);
        $tag->delete();

        return response()->json(['deleted' => true]);
    }

    protected function authorizeTag(Request $request, Tag $tag): void
    {
        $user = $request->user();

        if ($tag->organization_id) {
            $this->authorize('manage', $tag->organization);

            return;
        }

        abort_unless($tag->user_id === $user->id, 403);
    }
}
