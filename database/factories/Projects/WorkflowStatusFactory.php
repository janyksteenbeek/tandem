<?php

namespace Database\Factories\Projects;

use App\Enums\Projects\StatusCategory;
use App\Models\Projects\Workflow;
use App\Models\Projects\WorkflowStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStatus>
 */
class WorkflowStatusFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'category' => StatusCategory::Unstarted,
            'color' => 'sky',
            'position' => 0,
            'is_default' => false,
        ];
    }

    public function category(StatusCategory $category): static
    {
        return $this->state(fn () => ['category' => $category, 'color' => $category->color()]);
    }
}
