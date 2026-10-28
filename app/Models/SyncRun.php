<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $mail_account_id
 * @property SyncRunType $type
 * @property SyncRunStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $stats
 * @property string|null $error
 * @property-read MailAccount $mailAccount
 */
#[Fillable(['mail_account_id', 'type', 'status', 'started_at', 'finished_at', 'stats', 'error'])]
class SyncRun extends Model
{
    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public static function start(MailAccount $account, SyncRunType $type): self
    {
        return $account->syncRuns()->create([
            'type' => $type,
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    public function complete(array $stats = []): void
    {
        $this->update([
            'status' => SyncRunStatus::Completed,
            'finished_at' => now(),
            'stats' => $stats,
        ]);
    }

    public function fail(string $error): void
    {
        $this->update([
            'status' => SyncRunStatus::Failed,
            'finished_at' => now(),
            'error' => mb_substr($error, 0, 4000),
        ]);
    }

    protected function casts(): array
    {
        return [
            'type' => SyncRunType::class,
            'status' => SyncRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
        ];
    }
}
