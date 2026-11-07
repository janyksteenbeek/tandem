<?php

namespace App\Services\Projects;

use App\Enums\Projects\MilestoneStatus;
use App\Enums\Projects\ProjectHealth;
use App\Enums\Projects\ProjectMemberRole;
use App\Enums\Projects\ProjectStatus;
use App\Enums\Projects\ProjectVisibility;
use App\Events\Projects\Broadcast\ProjectUpdated;
use App\Models\Organization;
use App\Models\Projects\Milestone;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectActions
{
    public function __construct(protected AuditLogger $audit, protected HtmlSanitizer $sanitizer, protected ProjectProgress $progress) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Organization $organization, array $attributes, User $actor): Project
    {
        $product = isset($attributes['product_id']) ? Product::query()->where('organization_id', $organization->id)->whereKey($attributes['product_id'])->first() : null;

        if (isset($attributes['product_id']) && ! $product) {
            throw ValidationException::withMessages(['product_id' => 'That product does not exist in this organization.']);
        }

        $project = DB::transaction(function () use ($organization, $attributes, $actor, $product) {
            $project = Project::query()->create([
                'organization_id' => $organization->id,
                'product_id' => $product?->id,
                'name' => $attributes['name'],
                'description_html' => $this->sanitizer->sanitize($attributes['description_html'] ?? null),
                'icon' => $attributes['icon'] ?? null,
                'color' => $attributes['color'] ?? 'violet',
                'lead_id' => $attributes['lead_id'] ?? $actor->id,
                'status' => ProjectStatus::tryFrom($attributes['status'] ?? '') ?? ProjectStatus::Planned,
                'health' => ProjectHealth::tryFrom($attributes['health'] ?? '') ?? ProjectHealth::Unknown,
                'priority' => (int) ($attributes['priority'] ?? 0),
                'visibility' => ProjectVisibility::tryFrom($attributes['visibility'] ?? '') ?? ProjectVisibility::Organization,
                'start_date' => $attributes['start_date'] ?? null,
                'target_date' => $attributes['target_date'] ?? null,
            ]);

            $project->teams()->sync($this->teamIds($organization, $attributes['team_ids'] ?? []));
            $project->modules()->sync($product ? $product->modules()->whereIn('modules.id', $attributes['module_ids'] ?? [])->pluck('modules.id') : []);

            foreach ($this->memberIds($organization, $attributes['member_ids'] ?? []) as $userId) {
                $project->members()->attach($userId, ['role' => ProjectMemberRole::Member->value]);
            }

            return $project;
        });

        $this->audit->record('project.created', $organization, $actor, $project);
        $this->progress->recompute($project);

        return $project;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Project $project, array $attributes, User $actor): Project
    {
        $organization = $project->organization;

        DB::transaction(function () use ($project, $attributes, $organization) {
            $fill = array_intersect_key($attributes, array_flip(['name', 'icon', 'color', 'lead_id', 'priority', 'start_date', 'target_date']));

            if (array_key_exists('description_html', $attributes)) {
                $fill['description_html'] = $this->sanitizer->sanitize($attributes['description_html']);
            }

            foreach (['status' => ProjectStatus::class, 'health' => ProjectHealth::class, 'visibility' => ProjectVisibility::class] as $key => $enum) {
                if (isset($attributes[$key])) {
                    $fill[$key] = $enum::from($attributes[$key]);
                }
            }

            if (isset($fill['status'])) {
                $fill['completed_at'] = $fill['status'] === ProjectStatus::Completed ? ($project->completed_at ?? now()) : null;
            }

            if (array_key_exists('product_id', $attributes)) {
                $product = $attributes['product_id'] ? Product::query()->where('organization_id', $organization->id)->whereKey($attributes['product_id'])->first() : null;

                if ($attributes['product_id'] && ! $product) {
                    throw ValidationException::withMessages(['product_id' => 'That product does not exist in this organization.']);
                }

                $fill['product_id'] = $product?->id;

                if ($product?->id !== $project->product_id) {
                    $project->modules()->detach();
                }
            }

            $project->fill($fill)->save();

            if (array_key_exists('team_ids', $attributes) && $attributes['team_ids'] !== null) {
                $project->teams()->sync($this->teamIds($organization, $attributes['team_ids']));
            }

            if (array_key_exists('module_ids', $attributes) && $attributes['module_ids'] !== null) {
                $project->modules()->sync($project->product ? $project->product->modules()->whereIn('modules.id', $attributes['module_ids'])->pluck('modules.id') : []);
            }
        });

        $this->audit->record('project.updated', $organization, $actor, $project, array_intersect_key($attributes, array_flip(['status', 'health', 'visibility'])));
        ProjectUpdated::dispatch($project, 'updated');

        return $project->refresh();
    }

    /**
     * @param  array<int, array{user_id: int, role?: string}>  $members
     */
    public function syncMembers(Project $project, array $members, User $actor): Project
    {
        $sync = [];
        $allowed = $this->memberIds($project->organization, array_map(fn ($m) => (int) $m['user_id'], $members));

        foreach ($members as $member) {
            if (in_array((int) $member['user_id'], $allowed, true)) {
                $sync[(int) $member['user_id']] = ['role' => (ProjectMemberRole::tryFrom($member['role'] ?? 'member') ?? ProjectMemberRole::Member)->value];
            }
        }

        $project->members()->sync($sync);
        $this->audit->record('project.members_updated', $project->organization, $actor, $project, ['count' => count($sync)]);

        return $project->refresh();
    }

    public function delete(Project $project, User $actor): void
    {
        $project->delete();
        $this->audit->record('project.deleted', $project->organization, $actor, $project);
        ProjectUpdated::dispatch($project, 'deleted');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createMilestone(Project $project, array $attributes, User $actor): Milestone
    {
        $milestone = $project->milestones()->create([
            'organization_id' => $project->organization_id,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'target_date' => $attributes['target_date'] ?? null,
            'status' => MilestoneStatus::tryFrom($attributes['status'] ?? '') ?? MilestoneStatus::Planned,
            'position' => (int) $project->milestones()->max('position') + 1,
        ]);

        $this->audit->record('milestone.created', $project->organization, $actor, $milestone, ['project_id' => $project->id]);

        return $milestone;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateMilestone(Milestone $milestone, array $attributes, User $actor): Milestone
    {
        $fill = array_intersect_key($attributes, array_flip(['name', 'description', 'target_date']));

        if (isset($attributes['status'])) {
            $fill['status'] = MilestoneStatus::from($attributes['status']);
            $fill['completed_at'] = $fill['status'] === MilestoneStatus::Completed ? ($milestone->completed_at ?? now()) : null;
        }

        $milestone->fill($fill)->save();
        $this->audit->record('milestone.updated', $milestone->organization, $actor, $milestone);

        return $milestone;
    }

    public function deleteMilestone(Milestone $milestone, User $actor): void
    {
        $milestone->delete();
        $this->audit->record('milestone.deleted', $milestone->organization, $actor, $milestone);
    }

    /**
     * @param  array<int, int>  $orderedIds
     */
    public function reorderMilestones(Project $project, array $orderedIds): void
    {
        DB::transaction(function () use ($project, $orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                $project->milestones()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    protected function teamIds(Organization $organization, array $ids): array
    {
        return $organization->teams()->whereIn('teams.id', $ids)->pluck('teams.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    protected function memberIds(Organization $organization, array $ids): array
    {
        return $organization->members()->whereIn('users.id', $ids)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }
}
