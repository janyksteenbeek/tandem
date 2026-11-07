<?php

namespace Database\Factories\Projects;

use App\Models\Projects\Module;
use App\Models\Projects\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Module>
 */
class ModuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'description' => fake()->optional()->sentence(),
            'position' => 0,
        ];
    }
}
