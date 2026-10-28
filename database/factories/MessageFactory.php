<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        $text = fake()->paragraph()."\n\n".fake()->paragraph();

        return [
            'conversation_id' => Conversation::factory(),
            'mail_account_id' => fn (array $attributes) => Conversation::query()->whereKey($attributes['conversation_id'])->first()?->mail_account_id,
            'direction' => MessageDirection::Inbound,
            'message_id_header' => '<'.Str::uuid().'@'.fake()->domainName().'>',
            'provider_message_id' => Str::random(16),
            'from' => ['name' => fake()->name(), 'email' => fake()->safeEmail()],
            'to' => [['name' => null, 'email' => fake()->safeEmail()]],
            'cc' => [],
            'subject' => fake()->sentence(5),
            'snippet' => Str::limit($text, 180),
            'text_body' => $text,
            'html_body' => '<p>'.nl2br(e($text)).'</p>',
            'sent_at' => now()->subMinutes(fake()->numberBetween(1, 5000)),
            'received_at' => now(),
            'is_read' => false,
            'delivery_status' => DeliveryStatus::None,
        ];
    }

    public function outbound(): static
    {
        return $this->state(fn () => [
            'direction' => MessageDirection::Outbound,
            'is_read' => true,
            'delivery_status' => DeliveryStatus::Sent,
        ]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['is_read' => true]);
    }
}
