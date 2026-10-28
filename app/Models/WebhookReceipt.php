<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * @property int $id
 * @property string $provider
 * @property string $external_id
 * @property int|null $mail_account_id
 * @property string $status
 * @property array<string, mixed>|null $payload
 */
#[Fillable(['provider', 'external_id', 'mail_account_id', 'status', 'payload', 'received_at', 'processed_at'])]
class WebhookReceipt extends Model
{
    public $timestamps = false;

    /**
     * Claim a webhook delivery exactly once. Returns null when it was already processed.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function claim(string $provider, string $externalId, ?int $mailAccountId = null, ?array $payload = null): ?self
    {
        try {
            return static::create([
                'provider' => $provider,
                'external_id' => $externalId,
                'mail_account_id' => $mailAccountId,
                'payload' => $payload,
                'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || ($e->errorInfo[1] ?? null) === 1062) {
                return null;
            }

            throw $e;
        }
    }

    public function markProcessed(): void
    {
        $this->update(['status' => 'processed', 'processed_at' => now()]);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
