<?php

namespace Database\Factories;

use App\Enums\DraftKind;
use App\Models\Draft;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Draft>
 */
class DraftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inbox_id' => Inbox::factory(),
            'user_id' => User::factory(),
            'kind' => DraftKind::New,
            'to' => [['name' => null, 'email' => fake()->safeEmail()]],
            'cc' => [],
            'bcc' => [],
            'subject' => fake()->sentence(4),
            'html_body' => '<p>'.fake()->paragraph().'</p>',
            'last_saved_at' => now(),
        ];
    }
}
