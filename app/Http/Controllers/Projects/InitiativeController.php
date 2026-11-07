<?php

namespace App\Http\Controllers\Projects;

use App\Enums\Projects\InitiativeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Projects\MilestoneResource;
use App\Http\Resources\Projects\ProjectSummaryResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Organization;
use App\Models\Projects\Initiative;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use App\Services\Projects\Access\IssueAccess;
use App\Services\Projects\IssueLookups;
use App\Services\Projects\ProjectProgress;
use App\Services\Projects\RecentlyViewed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InitiativeController extends Controller
{
    public function __construct(protected AuditLogger $audit, protected HtmlSanitizer $sanitizer, protected IssueAccess $access, protected ProjectProgress $progress, protected IssueLookups $lookups, protected RecentlyViewed $recent) {}

    public function index(Request $request, Organization $currentOrganization): Response
    {
        $user = $request->user();

        $initiatives = Initiative::query()->where('organization_id', $currentOrganization->id)->with(['owner', 'projects.lead', 'projects.product', 'projects.teams'])->orderBy('name')->get();

        return Inertia::render('projects/initiatives/Index', [
            'initiatives' => $initiatives->map(fn (Initiative $i) => $this->summary($i, $user))->values()->all(),
            'can' => ['create' => $user->can('create', [Initiative::class, $currentOrganization])],
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
        ]);
    }

    public function show(Request $request, Organization $currentOrganization, Initiative $initiative): Response
    {
        abort_unless($initiative->organization_id === $currentOrganization->id, 404);
        $this->authorize('view', $initiative);
        $user = $request->user();
        $this->recent->touch($user, $currentOrganization, $initiative);

        $initiative->load(['owner', 'projects.lead', 'projects.product', 'projects.teams', 'milestones']);

        return Inertia::render('projects/initiatives/Show', [
            'initiative' => [
                ...$this->summary($initiative, $user),
                'description_html' => $initiative->description_html,
                'milestones' => MilestoneResource::collection($initiative->milestones)->resolve(),
                'can' => ['update' => $user->can('update', $initiative), 'delete' => $user->can('delete', $initiative)],
            ],
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
        ]);
    }

    public function store(Request $request, Organization $currentOrganization): RedirectResponse|JsonResponse
    {
        $this->authorize('create', [Initiative::class, $currentOrganization]);
        $data = $this->validated($request, $currentOrganization);

        $initiative = Initiative::query()->create([
            'organization_id' => $currentOrganization->id,
            'name' => $data['name'],
            'description_html' => $this->sanitizer->sanitize($data['description_html'] ?? null),
            'color' => $data['color'] ?? 'violet',
            'owner_id' => $data['owner_id'] ?? $request->user()->id,
            'status' => InitiativeStatus::tryFrom($data['status'] ?? '') ?? InitiativeStatus::Planned,
            'start_date' => $data['start_date'] ?? null,
            'target_date' => $data['target_date'] ?? null,
        ]);

        $this->syncProjects($initiative, $data['project_ids'] ?? []);
        $this->audit->record('initiative.created', $currentOrganization, $request->user(), $initiative);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['initiative' => $this->summary($initiative->load(['owner', 'projects.lead', 'projects.product', 'projects.teams']), $request->user())], 201);
        }

        return to_route('projects.initiatives.show', ['current_organization' => $currentOrganization->slug, 'initiative' => $initiative->slug]);
    }

    public function update(Request $request, Organization $currentOrganization, Initiative $initiative): JsonResponse
    {
        abort_unless($initiative->organization_id === $currentOrganization->id, 404);
        $this->authorize('update', $initiative);
        $data = $this->validated($request, $currentOrganization, false);

        $fill = array_intersect_key($data, array_flip(['name', 'color', 'owner_id', 'start_date', 'target_date']));

        if (array_key_exists('description_html', $data)) {
            $fill['description_html'] = $this->sanitizer->sanitize($data['description_html']);
        }

        if (isset($data['status'])) {
            $fill['status'] = InitiativeStatus::from($data['status']);
        }

        $initiative->fill($fill)->save();

        if (array_key_exists('project_ids', $data) && $data['project_ids'] !== null) {
            $this->syncProjects($initiative, $data['project_ids']);
        }

        $this->audit->record('initiative.updated', $currentOrganization, $request->user(), $initiative);

        return response()->json(['initiative' => $this->summary($initiative->fresh(['owner', 'projects.lead', 'projects.product', 'projects.teams']), $request->user())]);
    }

    public function destroy(Request $request, Organization $currentOrganization, Initiative $initiative): RedirectResponse
    {
        abort_unless($initiative->organization_id === $currentOrganization->id, 404);
        $this->authorize('delete', $initiative);

        $initiative->delete();
        $this->audit->record('initiative.deleted', $currentOrganization, $request->user(), $initiative);

        return to_route('projects.initiatives.index', ['current_organization' => $currentOrganization->slug]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, Organization $organization, bool $creating = true): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:140'],
            'description_html' => ['nullable', 'string', 'max:100000'],
            'color' => ['nullable', 'string', 'max:32'],
            'owner_id' => ['nullable', 'integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $organization->id)],
            'status' => ['nullable', Rule::enum(InitiativeStatus::class)],
            'start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer', Rule::exists('projects', 'id')->where('organization_id', $organization->id)],
        ]);
    }

    /**
     * @param  array<int, int>  $projectIds
     */
    protected function syncProjects(Initiative $initiative, array $projectIds): void
    {
        $sync = [];

        foreach (array_values($projectIds) as $position => $id) {
            $sync[(int) $id] = ['position' => $position];
        }

        $initiative->projects()->sync($sync);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Initiative $initiative, User $user): array
    {
        $projects = $initiative->projects->filter(fn (Project $p) => $this->access->projectLevelFor($user, $p) !== null)->values();

        return [
            'id' => $initiative->id,
            'uuid' => $initiative->uuid,
            'slug' => $initiative->slug,
            'name' => $initiative->name,
            'color' => $initiative->color,
            'status' => $initiative->status->value,
            'owner' => $initiative->owner ? UserSummaryResource::make($initiative->owner)->resolve() : null,
            'start_date' => $initiative->start_date?->toDateString(),
            'target_date' => $initiative->target_date?->toDateString(),
            'projects' => ProjectSummaryResource::collection($projects)->resolve(),
            'progress' => $this->progress->aggregate($projects),
        ];
    }
}
