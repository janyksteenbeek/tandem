<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $mail_account_id
 * @property string $name
 * @property string $email
 * @property string|null $reply_to
 * @property string|null $signature_html
 * @property bool $is_default
 * @property-read MailAccount $mailAccount
 */
#[Fillable(['mail_account_id', 'name', 'email', 'reply_to', 'signature_html', 'is_default'])]
class SenderIdentity extends Model
{
    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
