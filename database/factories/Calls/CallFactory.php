<?php

namespace Database\Factories\Calls;

use App\Enums\Calls\CallStatus;
use App\Models\Calls\Call;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'host_id' => User::factory(),
            'title' => fake()->sentence(3),
            'status' => CallStatus::Pending,
            'settings' => [],
        ];
    }

    public function live(): static
    {
        return $this->state(['status' => CallStatus::Live, 'started_at' => now()->subMinutes(5)]);
    }

    public function ended(): static
    {
        return $this->state(['status' => CallStatus::Ended, 'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(15)]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function withSettings(array $settings): static
    {
        return $this->state(fn (array $attributes) => ['settings' => [...($attributes['settings'] ?? []), ...$settings]]);
    }
}
