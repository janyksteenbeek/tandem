<?php

namespace App\Services\Projects;

use App\Enums\Projects\StatusCategory;
use App\Enums\Projects\WorkflowKind;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;
use App\Models\Projects\Product;
use App\Models\Projects\Workflow;
use App\Models\Projects\WorkflowStatus;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and edits workflows. Every workflow keeps one default status and at least one status
 * in each required category, so completion rules and boards always have somewhere to put work.
 */
class WorkflowActions
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * The organization's default issue workflow, created on first use.
     */
    public function defaultIssueWorkflow(Organization $organization): Workflow
    {
        $workflow = Workflow::query()->with('statuses')->where('organization_id', $organization->id)->where('scope_key', 'org')->first();

        return $workflow ?? $this->seed($organization, WorkflowKind::Issue, 'org', 'Issues', [
            ['Backlog', StatusCategory::Backlog, true],
            ['Todo', StatusCategory::Unstarted, false],
            ['In Progress', StatusCategory::Started, false],
            ['Done', StatusCategory::Completed, false],
            ['Canceled', StatusCategory::Canceled, false],
        ]);
    }

    /**
     * The workflow overall issue statuses come from: the product's own, or the organization default.
     */
    public function issueWorkflowFor(Organization $organization, ?Product $product): Workflow
    {
        if ($product?->issue_workflow_id) {
            $workflow = Workflow::query()->with('statuses')->find($product->issue_workflow_id);

            if ($workflow) {
                return $workflow;
            }
        }

        return $this->defaultIssueWorkflow($organization);
    }

    /**
     * A team's workstream workflow, created on first use.
     */
    public function forTeam(Team $team): Workflow
    {
        $workflow = Workflow::query()->with('statuses')->where('team_id', $team->id)->first();

        return $workflow ?? $this->seed($team->organization, WorkflowKind::Team, 'team:'.$team->id, $team->name, [
            ['Todo', StatusCategory::Unstarted, true],
            ['In Progress', StatusCategory::Started, false],
            ['In Review', StatusCategory::Started, false],
            ['Done', StatusCategory::Completed, false],
            ['Canceled', StatusCategory::Canceled, false],
        ], $team);
    }

    /**
     * Give a product its own issue workflow, copied from the organization default.
     */
    public function createProductWorkflow(Product $product, User $actor): Workflow
    {
        $default = $this->defaultIssueWorkflow($product->organization);

        $workflow = DB::transaction(function () use ($product, $default) {
            $workflow = Workflow::query()->create([
                'organization_id' => $product->organization_id,
                'kind' => WorkflowKind::Issue,
                'product_id' => $product->id,
                'scope_key' => 'product:'.$product->id,
                'name' => $product->name,
                'auto_start' => $default->auto_start,
                'auto_complete' => $default->auto_complete,
            ]);

            foreach ($default->statuses as $status) {
                $workflow->statuses()->create($status->only(['name', 'color', 'icon', 'category', 'position', 'is_default']));
            }

            $product->forceFill(['issue_workflow_id' => $workflow->id])->save();

            return $workflow;
        });

        $this->audit->record('workflow.created', $product->organization, $actor, $workflow, ['product_id' => $product->id]);

        return $workflow->load('statuses');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createStatus(Workflow $workflow, array $attributes, ?User $actor): WorkflowStatus
    {
        $category = $attributes['category'] instanceof StatusCategory ? $attributes['category'] : StatusCategory::from($attributes['category']);

        $status = DB::transaction(function () use ($workflow, $attributes, $category) {
            $position = ((int) $workflow->statuses()->max('position')) + 1;

            $status = $workflow->statuses()->create([
                'name' => $attributes['name'],
                'category' => $category,
                'color' => $attributes['color'] ?? $category->color(),
                'icon' => $attributes['icon'] ?? null,
                'position' => $position,
                'is_default' => false,
            ]);

            if (! empty($attributes['is_default'])) {
                $this->makeDefault($workflow, $status);
            }

            return $status;
        });

        $this->audit->record('workflow.status_created', $workflow->organization, $actor, $status, ['workflow_id' => $workflow->id]);

        return $status;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateStatus(WorkflowStatus $status, array $attributes, ?User $actor): WorkflowStatus
    {
        $workflow = $status->workflow;

        DB::transaction(function () use ($status, $attributes, $workflow) {
            $changes = [];

            if (array_key_exists('name', $attributes)) {
                $changes['name'] = $attributes['name'];
            }

            if (array_key_exists('color', $attributes) && $attributes['color'] !== null) {
                $changes['color'] = $attributes['color'];
            }

            if (array_key_exists('icon', $attributes)) {
                $changes['icon'] = $attributes['icon'];
            }

            if (array_key_exists('category', $attributes)) {
                $category = $attributes['category'] instanceof StatusCategory ? $attributes['category'] : StatusCategory::from($attributes['category']);

                if ($category !== $status->category) {
                    $this->assertCategoryRemovable($workflow, $status);
                    $changes['category'] = $category;
                }
            }

            $status->forceFill($changes)->save();

            if (isset($changes['category'])) {
                $this->syncCategoryColumn($status);
            }

            if (! empty($attributes['is_default'])) {
                $this->makeDefault($workflow, $status);
            }
        });

        $this->audit->record('workflow.status_updated', $workflow->organization, $actor, $status, ['workflow_id' => $workflow->id]);

        return $status->refresh();
    }

    /**
     * @param  array<int, int>  $orderedIds
     */
    public function reorder(Workflow $workflow, array $orderedIds, ?User $actor): void
    {
        DB::transaction(function () use ($workflow, $orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                $workflow->statuses()->whereKey($id)->update(['position' => $position]);
            }
        });

        $this->audit->record('workflow.reordered', $workflow->organization, $actor, $workflow);
    }

    public function setDefault(Workflow $workflow, WorkflowStatus $status, ?User $actor): void
    {
        DB::transaction(fn () => $this->makeDefault($workflow, $status));
        $this->audit->record('workflow.default_changed', $workflow->organization, $actor, $status, ['workflow_id' => $workflow->id]);
    }

    /**
     * Remove a status, remapping every issue or workstream that used it to the replacement.
     * The remap runs in chunks; a WorkflowStatusRemapped event lets listeners write activity.
     */
    public function deleteStatus(WorkflowStatus $status, WorkflowStatus $replacement, ?User $actor): int
    {
        $workflow = $status->workflow;

        if ($replacement->workflow_id !== $status->workflow_id || $replacement->id === $status->id) {
            throw ValidationException::withMessages(['replacement_status_id' => 'The replacement must be another status of the same workflow.']);
        }

        $this->assertCategoryRemovable($workflow, $status);

        $remapped = DB::transaction(function () use ($workflow, $status, $replacement) {
            $table = $workflow->kind === WorkflowKind::Team ? 'issue_workstreams' : 'issues';

            $count = DB::table($table)->where('status_id', $status->id)->update([
                'status_id' => $replacement->id,
                'status_category' => $replacement->category->value,
            ]);

            DB::table('workflow_transitions')->where('from_status_id', $status->id)->orWhere('to_status_id', $status->id)->delete();

            if ($status->is_default) {
                $this->makeDefault($workflow, $replacement);
            }

            $status->delete();

            return $count;
        });

        $this->audit->record('workflow.status_deleted', $workflow->organization, $actor, $workflow, [
            'status' => $status->name,
            'replacement_id' => $replacement->id,
            'remapped' => $remapped,
        ]);

        return $remapped;
    }

    /**
     * @param  array<int, array{from: int, to: int}>  $transitions
     */
    public function setTransitions(Workflow $workflow, array $transitions, ?User $actor): void
    {
        DB::transaction(function () use ($workflow, $transitions) {
            $workflow->transitions()->delete();

            foreach ($transitions as $transition) {
                $workflow->transitions()->create(['from_status_id' => $transition['from'], 'to_status_id' => $transition['to']]);
            }
        });

        $this->audit->record('workflow.transitions_updated', $workflow->organization, $actor, $workflow, ['count' => count($transitions)]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateWorkflow(Workflow $workflow, array $attributes, ?User $actor): Workflow
    {
        $workflow->forceFill(array_intersect_key($attributes, array_flip(['auto_start', 'auto_complete', 'name'])))->save();
        $this->audit->record('workflow.updated', $workflow->organization, $actor, $workflow, $attributes);

        return $workflow;
    }

    /**
     * With no transitions configured, every move is allowed.
     */
    public function transitionAllowed(Workflow $workflow, ?WorkflowStatus $from, WorkflowStatus $to): bool
    {
        if ($from === null || $from->id === $to->id) {
            return true;
        }

        $workflow->loadMissing('transitions');

        if ($workflow->transitions->isEmpty()) {
            return true;
        }

        return $workflow->transitions->contains(fn ($t) => $t->from_status_id === $from->id && $t->to_status_id === $to->id);
    }

    public function defaultStatusFor(Workflow $workflow, StatusCategory $category): WorkflowStatus
    {
        return $workflow->statusFor($category) ?? throw ValidationException::withMessages(['status' => "The workflow has no {$category->label()} status."]);
    }

    /**
     * @param  array<int, array{0: string, 1: StatusCategory, 2: bool}>  $statuses
     */
    protected function seed(Organization $organization, WorkflowKind $kind, string $scopeKey, string $name, array $statuses, ?Team $team = null): Workflow
    {
        return DB::transaction(function () use ($organization, $kind, $scopeKey, $name, $statuses, $team) {
            $workflow = Workflow::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'scope_key' => $scopeKey],
                ['kind' => $kind, 'team_id' => $team?->id, 'name' => $name],
            );

            if ($workflow->statuses()->doesntExist()) {
                foreach ($statuses as $position => [$label, $category, $default]) {
                    $workflow->statuses()->create([
                        'name' => $label,
                        'category' => $category,
                        'color' => $category->color(),
                        'position' => $position,
                        'is_default' => $default,
                    ]);
                }
            }

            return $workflow->load('statuses');
        });
    }

    protected function makeDefault(Workflow $workflow, WorkflowStatus $status): void
    {
        $workflow->statuses()->where('is_default', true)->update(['is_default' => false]);
        $status->forceFill(['is_default' => true])->save();
    }

    protected function assertCategoryRemovable(Workflow $workflow, WorkflowStatus $status): void
    {
        if (! in_array($status->category, StatusCategory::required(), true)) {
            return;
        }

        $others = $workflow->statuses()->where('category', $status->category->value)->whereKeyNot($status->id)->count();

        if ($others === 0) {
            throw ValidationException::withMessages(['status' => "A workflow needs at least one {$status->category->label()} status."]);
        }
    }

    protected function syncCategoryColumn(WorkflowStatus $status): void
    {
        Issue::query()->where('status_id', $status->id)->update(['status_category' => $status->category->value]);
        IssueWorkstream::query()->where('status_id', $status->id)->update(['status_category' => $status->category->value]);
    }
}
