<?php

namespace App\Services\Calls;

use App\Enums\Calls\ParticipantRole;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;

/**
 * What one participant may do in their call. Guests are checked here rather than in the user
 * policies because they have no user; the same answers are sent to the browser so the
 * interface never offers something the server will refuse.
 */
class ParticipantAbilities
{
    public function __construct(protected CallParticipant $participant, protected Call $call) {}

    public static function for(CallParticipant $participant): self
    {
        return new self($participant, $participant->call);
    }

    /** Admit, remove, mute, lock, change settings, end the meeting for everyone. */
    public function manage(): bool
    {
        return $this->participant->isInside() && $this->participant->role === ParticipantRole::Host;
    }

    public function shareScreen(): bool
    {
        if (! $this->participant->isInside()) {
            return false;
        }

        return $this->call->setting('screen_share') !== 'hosts' || $this->manage();
    }

    public function record(): bool
    {
        if (! $this->participant->isInside() || $this->participant->role === ParticipantRole::Guest) {
            return false;
        }

        return $this->manage() || $this->call->setting('recording') === 'members';
    }

    public function chat(): bool
    {
        return $this->participant->isInside() && ! $this->call->hasEnded();
    }

    public function upload(): bool
    {
        if (! $this->chat()) {
            return false;
        }

        return $this->participant->role !== ParticipantRole::Guest || (bool) $this->call->setting('guest_uploads');
    }

    public function invite(): bool
    {
        return $this->participant->isInside() && $this->participant->role !== ParticipantRole::Guest;
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'manage' => $this->manage(),
            'share_screen' => $this->shareScreen(),
            'record' => $this->record(),
            'chat' => $this->chat(),
            'upload' => $this->upload(),
            'invite' => $this->invite(),
        ];
    }
}
