<?php

namespace Database\Factories\Calls;

use App\Enums\Calls\ParticipantRole;
use App\Enums\Calls\ParticipantStatus;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A guest who was let in, unless a state says otherwise.
 *
 * @extends Factory<CallParticipant>
 */
class CallParticipantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'call_id' => Call::factory(),
            'user_id' => null,
            'name' => fake()->name(),
            'email' => null,
            'role' => ParticipantRole::Guest,
            'status' => ParticipantStatus::Admitted,
            'admitted_at' => now(),
        ];
    }

    public function forUser(User $user, ParticipantRole $role = ParticipantRole::Member): static
    {
        return $this->state(['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $role]);
    }

    public function host(User $user): static
    {
        return $this->forUser($user, ParticipantRole::Host);
    }

    public function waiting(): static
    {
        return $this->state(['status' => ParticipantStatus::Waiting, 'admitted_at' => null]);
    }

    public function joined(): static
    {
        return $this->state(['status' => ParticipantStatus::Joined, 'joined_at' => now()]);
    }
}
