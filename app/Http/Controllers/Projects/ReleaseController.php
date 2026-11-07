<?php

namespace App\Http\Controllers\Projects;

use App\Enums\Projects\IssueActivityType;
use App\Enums\Projects\ReleaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Http\Resources\Projects\ProductResource;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\Product;
use App\Models\Projects\Release;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use App\Services\Projects\IssueActions;
use App\Services\Projects\IssueLookups;
use App\Services\Projects\IssueQuery;
use App\Services\Projects\ProjectProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReleaseController extends Controller
{
    public function __construct(protected AuditLogger $audit, protected HtmlSanitizer $sanitizer, protected ProjectProgress $progress, protected IssueLookups $lookups) {}

    public function show(Request $request, Organization $currentOrganization, Product $product, Release $release): Response
    {
        $this->ensure($currentOrganization, $product, $release);
        $this->authorize('view', $release);
        $user = $request->user();

        $issues = $release->issues()->visibleTo($user)->with(IssueQuery::EAGER)->orderBy('status_category')->orderByDesc('priority')->get();

        return Inertia::render('projects/releases/Show', [
            'product' => ProductResource::make($product)->resolve(),
            'release' => $this->summary($release),
            'issues' => IssueListItemResource::collection($issues)->resolve(),
            'blockers' => IssueListItemResource::collection($issues->filter(fn (Issue $i) => $i->blocked_by_count > 0 || $i->blocked_workstreams_count > 0)->values())->resolve(),
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
            'can' => ['update' => $user->can('update', $release)],
        ]);
    }

    public function store(Request $request, Organization $currentOrganization, Product $product): JsonResponse
    {
        abort_unless($product->organization_id === $currentOrganization->id, 404);
        $this->authorize('update', $product);

        $data = $this->validated($request);

        $release = $product->releases()->create([...$data, 'created_by_id' => $request->user()->id]);
        $this->audit->record('release.created', $currentOrganization, $request->user(), $release, ['product_id' => $product->id]);

        return response()->json(['release' => $this->summary($release)], 201);
    }

    public function update(Request $request, Organization $currentOrganization, Product $product, Release $release): JsonResponse
    {
        $this->ensure($currentOrganization, $product, $release);
        $this->authorize('update', $release);

        $data = $this->validated($request, false);

        if (isset($data['status'])) {
            $data['released_at'] = $data['status'] === ReleaseStatus::Released->value ? ($release->released_at ?? now()) : null;
        }

        $release->fill($data)->save();
        $this->audit->record('release.updated', $currentOrganization, $request->user(), $release);

        return response()->json(['release' => $this->summary($release->fresh())]);
    }

    public function issues(Request $request, Organization $currentOrganization, Product $product, Release $release): JsonResponse
    {
        $this->ensure($currentOrganization, $product, $release);
        $this->authorize('update', $release);

        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:24'],
            'attached' => ['required', 'boolean'],
        ]);

        $issue = Issue::query()->where('organization_id', $currentOrganization->id)->where('identifier', strtoupper($data['identifier']))->firstOrFail();
        $this->authorize('update', $issue);

        if ($data['attached']) {
            $release->issues()->syncWithoutDetaching([$issue->id]);
        } else {
            $release->issues()->detach($issue->id);
        }

        app(IssueActions::class)->record($issue, IssueActivityType::ReleaseChanged, $request->user(), ['release' => $release->name, 'attached' => (bool) $data['attached']]);
        $this->progress->recomputeRelease($release);

        return response()->json(['release' => $this->summary($release->fresh())]);
    }

    public function destroy(Request $request, Organization $currentOrganization, Product $product, Release $release): JsonResponse
    {
        $this->ensure($currentOrganization, $product, $release);
        $this->authorize('delete', $release);

        $release->delete();
        $this->audit->record('release.deleted', $currentOrganization, $request->user(), $release);

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $creating = true): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:140'],
            'version' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::enum(ReleaseStatus::class)],
            'planned_at' => ['nullable', 'date'],
            'notes_html' => ['nullable', 'string', 'max:100000'],
        ]);

        if (array_key_exists('notes_html', $data)) {
            $data['notes_html'] = $this->sanitizer->sanitize($data['notes_html']);
        }

        return array_filter($data, fn ($v) => $v !== null) + (array_key_exists('notes_html', $data) ? ['notes_html' => $data['notes_html']] : []);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Release $release): array
    {
        return [
            'id' => $release->id,
            'uuid' => $release->uuid,
            'product_id' => $release->product_id,
            'name' => $release->name,
            'version' => $release->version,
            'status' => $release->status->value,
            'planned_at' => $release->planned_at?->toDateString(),
            'released_at' => $release->released_at?->toISOString(),
            'notes_html' => $release->notes_html,
            'issues_count' => $release->issues_count,
            'completed_issues_count' => $release->completed_issues_count,
            'progress' => $release->issues_count > 0 ? round($release->completed_issues_count / $release->issues_count, 3) : 0,
        ];
    }

    protected function ensure(Organization $organization, Product $product, Release $release): void
    {
        abort_unless($product->organization_id === $organization->id && $release->product_id === $product->id, 404);
    }
}
