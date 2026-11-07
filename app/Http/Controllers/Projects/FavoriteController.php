<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Projects\Cycle;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueView;
use App\Models\Projects\Module;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Team;
use App\Services\Projects\Favorites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FavoriteController extends Controller
{
    public function __construct(protected Favorites $favorites) {}

    public function toggle(Request $request, Organization $currentOrganization): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['product', 'module', 'project', 'team', 'cycle', 'issue', 'view'])],
            'id' => ['required', 'integer'],
        ]);

        $subject = match ($data['type']) {
            'product' => Product::query()->where('organization_id', $currentOrganization->id)->whereKey($data['id'])->firstOrFail(),
            'module' => Module::query()->whereHas('product', fn ($q) => $q->where('organization_id', $currentOrganization->id))->whereKey($data['id'])->firstOrFail(),
            'project' => Project::query()->where('organization_id', $currentOrganization->id)->whereKey($data['id'])->firstOrFail(),
            'team' => Team::query()->where('organization_id', $currentOrganization->id)->whereKey($data['id'])->firstOrFail(),
            'cycle' => Cycle::query()->whereHas('team', fn ($q) => $q->where('organization_id', $currentOrganization->id))->whereKey($data['id'])->firstOrFail(),
            'issue' => Issue::query()->where('organization_id', $currentOrganization->id)->whereKey($data['id'])->firstOrFail(),
            default => IssueView::query()->where('organization_id', $currentOrganization->id)->whereKey($data['id'])->firstOrFail(),
        };

        $this->authorize('view', $subject);

        return response()->json(['favorite' => $this->favorites->toggle($request->user(), $currentOrganization, $subject)]);
    }
}
