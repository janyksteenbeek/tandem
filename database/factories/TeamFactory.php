<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->randomElement(['Support', 'Sales', 'Finance', 'Success', 'Operations', 'Engineering', 'People']).' '.fake()->numberBetween(1, 999),
            'color' => fake()->randomElement(['violet', 'teal', 'amber', 'rose', 'sky', 'emerald']),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
