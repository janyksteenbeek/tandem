<?php

namespace Database\Factories\Projects;

use App\Enums\Projects\ReleaseStatus;
use App\Models\Projects\Product;
use App\Models\Projects\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => 'Release '.fake()->unique()->numerify('#.#'),
            'version' => fake()->numerify('#.#.#'),
            'status' => ReleaseStatus::Planned,
            'planned_at' => fake()->dateTimeBetween('+1 week', '+3 months'),
        ];
    }
}
