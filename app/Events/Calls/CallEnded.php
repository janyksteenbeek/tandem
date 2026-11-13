<?php

namespace App\Events\Calls;

use App\Models\Calls\Call;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The meeting is over for everyone: a host ended it or the room emptied.
 */
class CallEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(public Call $call) {}
}
