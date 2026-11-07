<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Resources\Projects\ProductSummaryResource;
use App\Http\Resources\Projects\ProjectSummaryResource;
use App\Http\Resources\Projects\TeamSummaryResource;
use App\Models\Organization;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Team;
use App\Services\Projects\Access\IssueAccess;
use App\Services\Projects\Reporting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(protected Reporting $reporting, protected IssueAccess $access) {}

    public function index(Request $request, Organization $currentOrganization): Response
    {
        $user = $request->user();
        $scope = $this->scope($request, $currentOrganization);

        return Inertia::render('projects/reports/Index', [
            'scope' => $scope['descriptor'],
            'report' => Inertia::defer(fn () => $scope['report']()),
            'options' => fn () => [
                'products' => ProductSummaryResource::collection(Product::query()->where('organization_id', $currentOrganization->id)->active()->orderBy('name')->get()->filter(fn (Product $p) => $this->access->productLevelFor($user, $p) !== null)->values())->resolve(),
                'projects' => ProjectSummaryResource::collection(Project::query()->tap(fn ($q) => $this->access->applyProjectVisibility($q, $user, $currentOrganization->id))->whereNotIn('status', ['completed', 'canceled'])->with(['lead', 'product'])->orderBy('name')->get())->resolve(),
                'teams' => TeamSummaryResource::collection($currentOrganization->teams()->get())->resolve(),
            ],
        ]);
    }

    public function data(Request $request, Organization $currentOrganization): JsonResponse
    {
        return response()->json(['report' => $this->scope($request, $currentOrganization)['report']()]);
    }

    /**
     * @return array{descriptor: array<string, mixed>, report: callable(): array<string, mixed>}
     */
    protected function scope(Request $request, Organization $organization): array
    {
        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['organization', 'product', 'project', 'team'])],
            'id' => ['nullable', 'string', 'max:140'],
            'days' => ['nullable', 'integer', 'min:7', 'max:365'],
        ]);

        $user = $request->user();
        $since = now()->subDays((int) ($data['days'] ?? 30));

        switch ($data['scope'] ?? 'organization') {
            case 'product':
                $product = Product::query()->where('organization_id', $organization->id)->where('slug', (string) ($data['id'] ?? ''))->firstOrFail();
                $this->authorize('view', $product);

                return ['descriptor' => ['type' => 'product', 'id' => $product->slug, 'name' => $product->name, 'days' => $since->diffInDays(now())], 'report' => fn () => $this->reporting->product($product, $user, $since)];
            case 'project':
                $project = Project::query()->where('organization_id', $organization->id)->where('slug', (string) ($data['id'] ?? ''))->firstOrFail();
                $this->authorize('view', $project);

                return ['descriptor' => ['type' => 'project', 'id' => $project->slug, 'name' => $project->name, 'days' => $since->diffInDays(now())], 'report' => fn () => $this->reporting->project($project, $user, $since)];
            case 'team':
                $team = Team::query()->where('organization_id', $organization->id)->where('slug', (string) ($data['id'] ?? ''))->firstOrFail();
                $this->authorize('view', $team);

                return ['descriptor' => ['type' => 'team', 'id' => $team->slug, 'name' => $team->name, 'days' => $since->diffInDays(now())], 'report' => fn () => $this->reporting->team($team, $user, $since)];
            default:
                return ['descriptor' => ['type' => 'organization', 'id' => null, 'name' => $organization->name, 'days' => $since->diffInDays(now())], 'report' => fn () => $this->reporting->organization($organization, $user, $since)];
        }
    }
}
