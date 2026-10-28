<?php

namespace App\Events\Mail;

use App\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an outbound message reached a terminal delivery state (sent or failed).
 */
class MessageSent
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}
}
