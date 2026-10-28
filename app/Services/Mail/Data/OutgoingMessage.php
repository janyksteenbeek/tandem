<?php

namespace App\Services\Mail\Data;

/**
 * Canonical representation of a message about to be sent through a provider.
 */
final readonly class OutgoingMessage
{
    /**
     * @param  array<int, Address>  $to
     * @param  array<int, Address>  $cc
     * @param  array<int, Address>  $bcc
     * @param  array<int, string>  $references
     * @param  array<int, OutgoingAttachment>  $attachments
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public Address $from,
        public array $to,
        public string $subject,
        public string $htmlBody,
        public ?string $textBody = null,
        public array $cc = [],
        public array $bcc = [],
        public ?Address $replyTo = null,
        public ?string $messageId = null,
        public ?string $inReplyTo = null,
        public array $references = [],
        public array $attachments = [],
        public array $headers = [],
        public ?string $providerThreadId = null,
    ) {}
}
