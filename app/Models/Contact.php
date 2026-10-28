<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property int|null $user_id
 * @property string $email
 * @property string|null $name
 * @property string|null $company
 * @property string|null $avatar_url
 * @property string|null $notes
 * @property int $message_count
 */
#[Fillable(['organization_id', 'user_id', 'email', 'name', 'company', 'avatar_url', 'notes', 'message_count', 'last_seen_at'])]
class Contact extends Model
{
    protected static function booted(): void
    {
        static::saving(fn (Contact $contact) => $contact->email = Str::lower(trim($contact->email)));
    }

    /**
     * Record that we saw an address in a scope (organization or personal user).
     */
    public static function touchAddress(?int $organizationId, ?int $userId, string $email, ?string $name = null): void
    {
        $email = Str::lower(trim($email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $contact = static::query()
            ->when($organizationId, fn (Builder $q) => $q->where('organization_id', $organizationId))
            ->when(! $organizationId, fn (Builder $q) => $q->whereNull('organization_id')->where('user_id', $userId))
            ->where('email', $email)
            ->first();

        if (! $contact) {
            $contact = new self([
                'organization_id' => $organizationId,
                'user_id' => $organizationId ? null : $userId,
                'email' => $email,
            ]);
        }

        if ($name && (! $contact->name || $contact->name === $email)) {
            $contact->name = Str::limit(trim($name, " \t\"'"), 190, '');
        }

        $contact->message_count = ($contact->message_count ?? 0) + 1;
        $contact->last_seen_at = now();
        $contact->save();
    }

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
