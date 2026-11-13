<?php

namespace Database\Factories\Projects;

use App\Enums\Projects\InitiativeStatus;
use App\Models\Organization;
use App\Models\Projects\Initiative;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Initiative>
 */
class InitiativeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => rtrim(fake()->unique()->sentence(3), '.'),
            'status' => InitiativeStatus::Planned,
        ];
    }
}
