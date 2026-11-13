<?php

namespace Database\Factories\Projects;

use App\Models\Organization;
use App\Models\Projects\IssueLabel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueLabel>
 */
class IssueLabelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'color' => fake()->randomElement(['violet', 'teal', 'amber', 'rose', 'sky', 'emerald', 'slate']),
        ];
    }
}
