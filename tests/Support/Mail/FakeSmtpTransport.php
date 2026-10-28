<?php

namespace Tests\Support\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Collects messages instead of talking to an SMTP server.
 */
class FakeSmtpTransport implements TransportInterface
{
    /** @var array<int, SentMessage> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $sent = new SentMessage($message, $envelope ?? Envelope::create($message));
        $this->sent[] = $sent;

        return $sent;
    }

    public function __toString(): string
    {
        return 'fake://smtp';
    }
}
