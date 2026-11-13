<?php

namespace App\Models\Calls;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $message_id
 * @property int $participant_id
 * @property string $emoji
 * @property-read CallMessage $message
 * @property-read CallParticipant $participant
 */
#[Fillable(['message_id', 'participant_id', 'emoji'])]
class CallMessageReaction extends Model
{
    /** @return BelongsTo<CallMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(CallMessage::class, 'message_id');
    }

    /** @return BelongsTo<CallParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(CallParticipant::class, 'participant_id');
    }
}
