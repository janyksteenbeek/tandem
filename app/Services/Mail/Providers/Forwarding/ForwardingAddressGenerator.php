<?php

namespace App\Services\Mail\Providers\Forwarding;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates and recognises the unique inbound addresses on the forwarding domain
 * ({local}@forward.example.com) that customers point their groups and mailboxes at.
 */
class ForwardingAddressGenerator
{
    /**
     * Lowercase alphabet without visually ambiguous characters (0/o, 1/l).
     */
    public const ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    public const LOCAL_PART_LENGTH = 10;

    public const TOKEN_LENGTH = 48;

    /**
     * Create a new inbound address for the inbox. The channel decides how the address is
     * presented (a plain forwarding rule or a Google Group member); delivery is identical.
     */
    public function generate(Inbox $inbox, User $creator, ?string $label = null, MailProvider $channel = MailProvider::Forwarding): MailAccount
    {
        if (! $channel->usesForwardingAddress()) {
            throw new InvalidArgumentException(sprintf('[%s] is not a forwarding-based channel.', $channel->value));
        }

        $label = $label !== null && trim($label) !== '' ? trim($label) : null;

        return MailAccount::create([
            'organization_id' => $inbox->organization_id,
            'user_id' => null,
            'inbox_id' => $inbox->id,
            'provider' => $channel,
            'email_address' => $this->uniqueAddress(),
            'display_name' => $label ?? $inbox->name,
            'credentials' => null,
            'settings' => ['label' => $label, 'created_by' => $creator->id],
            'sync_state' => ['received_count' => 0],
            'status' => MailAccountStatus::Connected,
            'inbound_token' => Str::random(self::TOKEN_LENGTH),
            'initial_sync_completed_at' => now(),
        ]);
    }

    public function domain(): string
    {
        return Str::lower(trim((string) config('tandem.forwarding.domain')));
    }

    public function isForwardingAddress(string $email): bool
    {
        $domain = $this->domain();

        return $domain !== '' && Str::endsWith($this->normalizeRecipient($email), '@'.$domain);
    }

    /**
     * Lowercase, strip angle brackets and "+tag" sub-addressing so every variant resolves to the same account.
     */
    public function normalizeRecipient(string $email): string
    {
        $email = trim($email);

        if (preg_match('/<([^>]+)>/', $email, $matches)) {
            $email = $matches[1];
        }

        $email = Str::lower(trim($email, " \t\r\n<>"));

        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return Str::before($local, '+').'@'.$domain;
    }

    public function uniqueAddress(): string
    {
        do {
            $candidate = $this->randomLocalPart().'@'.$this->domain();
        } while (MailAccount::withTrashed()->where('email_address', $candidate)->exists());

        return $candidate;
    }

    public function randomLocalPart(int $length = self::LOCAL_PART_LENGTH): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $local = '';

        for ($i = 0; $i < $length; $i++) {
            $local .= $alphabet[random_int(0, $max)];
        }

        return $local;
    }
}
