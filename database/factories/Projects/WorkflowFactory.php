<?php

namespace Database\Factories\Projects;

use App\Enums\Projects\StatusCategory;
use App\Enums\Projects\WorkflowKind;
use App\Models\Organization;
use App\Models\Projects\Workflow;
use App\Models\Projects\WorkflowStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'kind' => WorkflowKind::Issue,
            'scope_key' => 'custom:'.Str::random(6),
            'name' => 'Workflow',
            'auto_start' => true,
            'auto_complete' => true,
        ];
    }

    public function team(): static
    {
        return $this->state(fn () => ['kind' => WorkflowKind::Team]);
    }

    /**
     * Creates the standard five statuses after the workflow exists.
     */
    public function withDefaultStatuses(): static
    {
        return $this->afterCreating(function (Workflow $workflow) {
            $defaults = [
                ['Backlog', StatusCategory::Backlog, false],
                ['Todo', StatusCategory::Unstarted, true],
                ['In Progress', StatusCategory::Started, false],
                ['Done', StatusCategory::Completed, false],
                ['Canceled', StatusCategory::Canceled, false],
            ];

            foreach ($defaults as $position => [$name, $category, $default]) {
                WorkflowStatus::query()->create([
                    'workflow_id' => $workflow->id,
                    'name' => $name,
                    'category' => $category,
                    'color' => $category->color(),
                    'position' => $position,
                    'is_default' => $default,
                ]);
            }
        });
    }
}
