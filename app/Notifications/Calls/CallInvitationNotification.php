<?php

namespace App\Notifications\Calls;

use App\Models\Calls\Call;
use App\Models\User;

class CallInvitationNotification extends CallNotification
{
    public function __construct(Call $call, public User $inviter)
    {
        parent::__construct($call);
    }

    protected function kind(): string
    {
        return 'call_invitation';
    }

    protected function title(): string
    {
        return $this->inviter->name.' invited you to a meeting';
    }

    protected function url(): string
    {
        return route('meet.show', ['call' => $this->call->code]);
    }
}
