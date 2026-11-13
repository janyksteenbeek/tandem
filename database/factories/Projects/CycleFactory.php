<?php

namespace Database\Factories\Projects;

use App\Enums\Projects\CycleStatus;
use App\Models\Projects\Cycle;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cycle>
 */
class CycleFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->startOfWeek();

        return [
            'team_id' => Team::factory(),
            'number' => fake()->unique()->numberBetween(1, 10000),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addWeeks(2)->subDay(),
            'status' => CycleStatus::Upcoming,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => CycleStatus::Active, 'started_at' => now()]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => CycleStatus::Completed, 'started_at' => now()->subWeeks(2), 'completed_at' => now()]);
    }
}
