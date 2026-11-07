<?php

namespace Database\Factories\Mail;

use App\Models\Mail\Comment;
use App\Models\Mail\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
            'mentions' => [],
        ];
    }
}
