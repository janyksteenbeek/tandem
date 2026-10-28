<?php

namespace Database\Factories;

use App\Enums\InboxType;
use App\Models\Inbox;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inbox>
 */
class InboxFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement(['Support', 'Sales', 'Finance', 'Hello', 'Partnerships', 'Billing']);

        return [
            'organization_id' => Organization::factory(),
            'user_id' => null,
            'type' => InboxType::Shared,
            'name' => $name,
            'color' => fake()->randomElement(['violet', 'teal', 'amber', 'rose', 'sky', 'emerald']),
            'icon' => 'inbox',
            'email_address' => strtolower($name).'@'.fake()->domainName(),
            'settings' => [],
        ];
    }

    public function personal(?User $user = null): static
    {
        return $this->state(fn () => [
            'organization_id' => null,
            'user_id' => $user ? $user->id : User::factory(),
            'type' => InboxType::Personal,
            'name' => fake()->safeEmail(),
            'email_address' => fn (array $attributes) => $attributes['name'],
        ]);
    }

    public function shared(): static
    {
        return $this->state(fn () => ['type' => InboxType::Shared]);
    }
}
