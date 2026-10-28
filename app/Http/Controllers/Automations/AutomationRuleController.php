<?php

namespace App\Http\Controllers\Automations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automations\ReorderAutomationRulesRequest;
use App\Http\Requests\Automations\SaveAutomationRuleRequest;
use App\Http\Requests\Automations\TestAutomationRuleRequest;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\Automations\AutomationCatalog;
use App\Services\Automations\AutomationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AutomationRuleController extends Controller
{
    public function index(Request $request, Organization $currentOrganization): Response
    {
        $organization = $currentOrganization;

        $rules = $organization->automationRules()
            ->with('inbox')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (AutomationRule $rule) => $this->ruleSummary($rule));

        return Inertia::render('settings/automations/Index', [
            'organization' => $this->organizationSummary($organization),
            'rules' => $rules,
            'catalog' => AutomationCatalog::toArray(),
        ]);
    }

    public function create(Request $request, Organization $currentOrganization): Response
    {
        $organization = $currentOrganization;

        return Inertia::render('settings/automations/Edit', [
            'organization' => $this->organizationSummary($organization),
            'rule' => null,
            'recentRuns' => [],
            ...$this->editorProps($organization),
        ]);
    }

    public function store(SaveAutomationRuleRequest $request, Organization $currentOrganization): RedirectResponse
    {
        $organization = $currentOrganization;

        $rule = $organization->automationRules()->create($request->rulePayload() + [
            'created_by_id' => $request->user()->id,
            'position' => ((int) $organization->automationRules()->max('position')) + 1,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation created.')]);

        return to_route('automations.edit', ['current_organization' => $organization->slug, 'rule' => $rule->uuid]);
    }

    public function edit(Request $request, Organization $currentOrganization, AutomationRule $rule): Response
    {
        $organization = $currentOrganization;
        $this->ensureBelongs($rule, $organization);

        $rule->load('inbox');

        return Inertia::render('settings/automations/Edit', [
            'organization' => $this->organizationSummary($organization),
            'rule' => $this->ruleDetail($rule),
            'recentRuns' => $rule->runs()->with('conversation:id,uuid,subject')->latest('created_at')->latest('id')->limit(20)->get()->map(fn (AutomationRun $run) => $this->runSummary($run)),
            ...$this->editorProps($organization),
        ]);
    }

    public function update(SaveAutomationRuleRequest $request, Organization $currentOrganization, AutomationRule $rule): RedirectResponse
    {
        $organization = $currentOrganization;
        $this->ensureBelongs($rule, $organization);

        $rule->update($request->rulePayload());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation updated.')]);

        return to_route('automations.edit', ['current_organization' => $organization->slug, 'rule' => $rule->uuid]);
    }

    public function destroy(Request $request, Organization $currentOrganization, AutomationRule $rule): RedirectResponse
    {
        $organization = $currentOrganization;
        $this->ensureBelongs($rule, $organization);

        $rule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation deleted.')]);

        return to_route('automations.index', ['current_organization' => $organization->slug]);
    }

    public function toggle(Request $request, Organization $currentOrganization, AutomationRule $rule): RedirectResponse|JsonResponse
    {
        $organization = $currentOrganization;
        $this->ensureBelongs($rule, $organization);

        $validated = $request->validate(['is_active' => ['sometimes', 'boolean']]);

        $rule->update(['is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : ! $rule->is_active]);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['uuid' => $rule->uuid, 'is_active' => $rule->is_active]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $rule->is_active ? __('Automation enabled.') : __('Automation paused.')]);

        return back();
    }

    public function reorder(ReorderAutomationRulesRequest $request, Organization $currentOrganization): RedirectResponse|JsonResponse
    {
        $organization = $currentOrganization;

        DB::transaction(function () use ($request, $organization) {
            foreach (array_values($request->validated('rules')) as $position => $uuid) {
                $organization->automationRules()->where('uuid', $uuid)->update(['position' => $position + 1]);
            }
        });

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function runs(Request $request, Organization $currentOrganization, AutomationRule $rule): JsonResponse
    {
        $organization = $currentOrganization;
        $this->ensureBelongs($rule, $organization);

        $runs = $rule->runs()
            ->with('conversation:id,uuid,subject')
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->latest('created_at')
            ->latest('id')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100))
            ->withQueryString();

        return response()->json([
            'data' => collect($runs->items())->map(fn (AutomationRun $run) => $this->runSummary($run))->values(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    public function test(TestAutomationRuleRequest $request, Organization $currentOrganization, AutomationRule $rule, AutomationEngine $engine): JsonResponse
    {
        $organization = $currentOrganization;
        $this->ensureBelongs($rule, $organization);

        $conversation = Conversation::query()
            ->where('organization_id', $organization->id)
            ->where('uuid', $request->validated('conversation_uuid'))
            ->firstOrFail();

        $conditions = $request->has('conditions') ? $request->validated('conditions') : ($rule->conditions ?? []);
        $match = $request->validated('match') ?? $rule->match;

        $result = $engine->dryRun($conditions, $match, $conversation);

        return response()->json([
            'rule' => ['uuid' => $rule->uuid, 'name' => $rule->name],
            'conversation' => ['uuid' => $conversation->uuid, 'subject' => $conversation->subject],
            'matched' => $result['matched'],
            'match' => $result['match'],
            'conditions' => $result['conditions'],
            'would_run' => $result['matched'] && $rule->is_active && ($rule->inbox_id === null || $rule->inbox_id === $conversation->inbox_id),
        ]);
    }

    protected function ensureBelongs(AutomationRule $rule, Organization $organization): void
    {
        abort_unless($rule->organization_id === $organization->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    protected function organizationSummary(Organization $organization): array
    {
        return ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug];
    }

    /**
     * @return array<string, mixed>
     */
    protected function editorProps(Organization $organization): array
    {
        return [
            'inboxes' => $organization->inboxes()->get()->map(fn (Inbox $inbox) => ['uuid' => $inbox->uuid, 'name' => $inbox->name, 'color' => $inbox->color])->values(),
            'tags' => $organization->tags()->get()->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'color' => $tag->color])->values(),
            'users' => $organization->members()->orderBy('name')->get()->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatarUrl()])->values(),
            'teams' => $organization->teams()->with('members:id')->get()->map(fn (Team $team) => ['id' => $team->id, 'name' => $team->name, 'color' => $team->color, 'member_count' => $team->members->count()])->values(),
            'templates' => $organization->messageTemplates()->with('inbox:id,uuid,name')->get()->map(fn (MessageTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'category' => $template->category,
                'subject' => $template->subject,
                'inbox' => $template->inbox ? ['uuid' => $template->inbox->uuid, 'name' => $template->inbox->name] : null,
            ])->values(),
            'catalog' => AutomationCatalog::toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ruleSummary(AutomationRule $rule): array
    {
        return [
            'uuid' => $rule->uuid,
            'name' => $rule->name,
            'description' => $rule->description,
            'trigger' => ['value' => $rule->trigger->value, 'label' => $rule->trigger->label()],
            'match' => $rule->match,
            'is_active' => $rule->is_active,
            'position' => $rule->position,
            'cooldown_minutes' => $rule->cooldown_minutes,
            'run_count' => $rule->run_count,
            'last_run_at' => $rule->last_run_at?->toIso8601String(),
            'inbox' => $rule->inbox ? ['uuid' => $rule->inbox->uuid, 'name' => $rule->inbox->name] : null,
            'condition_count' => count($rule->conditions ?? []),
            'action_types' => array_values(array_map(fn (array $action) => $action['type'], $rule->actions ?? [])),
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ruleDetail(AutomationRule $rule): array
    {
        return $this->ruleSummary($rule) + [
            'inbox_uuid' => $rule->inbox?->uuid,
            'conditions' => $rule->conditions ?? [],
            'actions' => $rule->actions ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function runSummary(AutomationRun $run): array
    {
        return [
            'id' => $run->id,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'details' => $run->details,
            'conversation' => $run->conversation ? ['uuid' => $run->conversation->uuid, 'subject' => $run->conversation->subject] : null,
            'created_at' => $run->created_at->toIso8601String(),
        ];
    }
}
