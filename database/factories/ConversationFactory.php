<?php

namespace Database\Factories;

use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Inbox;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        $subject = fake()->sentence(fake()->numberBetween(3, 8));
        $sentAt = fake()->dateTimeBetween('-30 days');

        return [
            'inbox_id' => Inbox::factory(),
            'organization_id' => fn (array $attributes) => Inbox::query()->whereKey($attributes['inbox_id'])->first()?->organization_id,
            'subject' => rtrim($subject, '.'),
            'snippet' => Str::limit(fake()->paragraph(), 180),
            'status' => ConversationStatus::Open,
            'priority' => ConversationPriority::None,
            'participants' => [['name' => fake()->name(), 'email' => fake()->safeEmail()]],
            'provider_thread_id' => Str::random(16),
            'message_count' => 1,
            'unread_count' => 1,
            'is_read' => false,
            'first_message_at' => $sentAt,
            'last_message_at' => $sentAt,
            'last_inbound_at' => $sentAt,
            'last_activity_at' => $sentAt,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => ConversationStatus::Closed, 'closed_at' => now()]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['is_read' => true, 'unread_count' => 0]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['is_archived' => true, 'in_inbox' => false]);
    }
}
