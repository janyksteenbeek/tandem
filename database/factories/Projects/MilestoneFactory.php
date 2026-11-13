<?php

namespace Database\Factories\Projects;

use App\Enums\Projects\MilestoneStatus;
use App\Models\Projects\Milestone;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'organization_id' => fn (array $attributes) => Project::query()->whereKey($attributes['project_id'])->value('organization_id'),
            'name' => rtrim(fake()->unique()->sentence(2), '.'),
            'status' => MilestoneStatus::Planned,
            'target_date' => fake()->dateTimeBetween('+1 week', '+3 months'),
        ];
    }
}
