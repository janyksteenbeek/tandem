<?php

namespace Database\Factories\Projects;

use App\Enums\Projects\ProductStatus;
use App\Models\Organization;
use App\Models\Projects\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = rtrim(fake()->unique()->sentence(2), '.');

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'key' => strtoupper(Str::substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'PRD', 0, 4)).fake()->numberBetween(1, 99),
            'description' => fake()->optional()->sentence(),
            'color' => fake()->randomElement(['violet', 'teal', 'amber', 'rose', 'sky', 'emerald']),
            'status' => ProductStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Archived]);
    }
}
