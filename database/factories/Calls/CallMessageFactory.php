<?php

namespace Database\Factories\Calls;

use App\Models\Calls\Call;
use App\Models\Calls\CallMessage;
use App\Models\Calls\CallParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallMessage>
 */
class CallMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'call_id' => Call::factory(),
            'participant_id' => CallParticipant::factory(),
            'body' => fake()->sentence(),
            'type' => 'text',
        ];
    }
}
