<?php

namespace Database\Factories;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MailAccount>
 */
class MailAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inbox_id' => Inbox::factory(),
            'organization_id' => fn (array $attributes) => Inbox::query()->whereKey($attributes['inbox_id'])->first()?->organization_id,
            'user_id' => fn (array $attributes) => Inbox::query()->whereKey($attributes['inbox_id'])->first()?->user_id,
            'provider' => MailProvider::Imap,
            'email_address' => fake()->unique()->safeEmail(),
            'display_name' => fake()->name(),
            'credentials' => ['password' => 'secret'],
            'settings' => [
                'imap' => ['host' => 'imap.example.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'user'],
                'smtp' => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'user'],
            ],
            'sync_state' => [],
            'status' => MailAccountStatus::Connected,
            'last_synced_at' => now(),
            'initial_sync_completed_at' => now(),
        ];
    }

    public function gmail(): static
    {
        return $this->state(fn () => [
            'provider' => MailProvider::Gmail,
            'email_address' => fake()->unique()->userName().'@gmail.com',
            'credentials' => [
                'access_token' => Str::random(40),
                'refresh_token' => Str::random(40),
                'expires_at' => now()->addHour()->timestamp,
            ],
            'settings' => [],
            'sync_state' => ['history_id' => '1000'],
        ]);
    }

    public function forwarding(): static
    {
        return $this->state(fn () => [
            'provider' => MailProvider::Forwarding,
            'email_address' => Str::lower(Str::random(10)).'@'.config('tandem.forwarding.domain'),
            'credentials' => null,
            'settings' => [],
            'inbound_token' => Str::random(48),
        ]);
    }

    public function personal(?User $user = null): static
    {
        return $this->state(fn () => [
            'inbox_id' => Inbox::factory()->personal($user),
            'organization_id' => null,
        ]);
    }

    public function needsReauth(): static
    {
        return $this->state(fn () => ['status' => MailAccountStatus::NeedsReauth, 'last_error' => 'invalid_grant']);
    }
}
