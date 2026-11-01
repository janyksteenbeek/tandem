<?php

namespace Database\Factories;

use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SsoIdentity>
 */
class SsoIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'google',
            'provider_id' => (string) fake()->unique()->numerify('####################'),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'avatar' => null,
            'last_used_at' => null,
        ];
    }
}
