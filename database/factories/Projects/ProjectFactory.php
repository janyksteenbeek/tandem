<?php

namespace Database\Factories\Projects;

use App\Enums\Projects\ProjectHealth;
use App\Enums\Projects\ProjectStatus;
use App\Enums\Projects\ProjectVisibility;
use App\Models\Organization;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => rtrim(fake()->unique()->sentence(3), '.'),
            'status' => ProjectStatus::Planned,
            'health' => ProjectHealth::Unknown,
            'visibility' => ProjectVisibility::Organization,
            'color' => fake()->randomElement(['violet', 'teal', 'amber', 'rose', 'sky', 'emerald']),
        ];
    }

    public function private(): static
    {
        return $this->state(fn () => ['visibility' => ProjectVisibility::Private]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn () => ['organization_id' => $product->organization_id, 'product_id' => $product->id]);
    }
}
