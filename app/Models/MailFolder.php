<?php

namespace App\Models;

use App\Enums\FolderType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $mail_account_id
 * @property int|null $tag_id
 * @property string $provider_id
 * @property string $name
 * @property FolderType $type
 * @property int|null $uid_validity
 * @property int|null $uid_next
 * @property int|null $last_synced_uid
 * @property int $message_count
 * @property bool $is_selectable
 * @property bool $sync_enabled
 * @property CarbonInterface|null $last_synced_at
 * @property-read MailAccount $mailAccount
 * @property-read Tag|null $tag
 */
#[Fillable(['mail_account_id', 'tag_id', 'provider_id', 'name', 'type', 'attributes', 'uid_validity', 'uid_next', 'last_synced_uid', 'message_count', 'is_selectable', 'sync_enabled', 'last_synced_at'])]
class MailFolder extends Model
{
    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    protected function casts(): array
    {
        return [
            'type' => FolderType::class,
            'attributes' => 'array',
            'is_selectable' => 'boolean',
            'sync_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
