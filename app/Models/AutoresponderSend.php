<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $automation_rule_id
 * @property int|null $conversation_id
 * @property string $recipient_email
 */
#[Fillable(['automation_rule_id', 'conversation_id', 'recipient_email', 'sent_at'])]
class AutoresponderSend extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
