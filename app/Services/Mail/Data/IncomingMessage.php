<?php

namespace App\Services\Mail\Data;

use Carbon\CarbonInterface;

/**
 * Canonical representation of a message fetched from any provider.
 */
final readonly class IncomingMessage
{
    /**
     * @param  array<int, string>  $references
     * @param  array<int, Address>  $to
     * @param  array<int, Address>  $cc
     * @param  array<int, Address>  $bcc
     * @param  array<int, Address>  $replyTo
     * @param  array<string, string|array<int, string>>  $headers
     * @param  array<int, IncomingAttachment>  $attachments
     * @param  array<int, string>  $labels
     */
    public function __construct(
        public ?string $messageId,
        public ?Address $from,
        public array $to,
        public ?string $subject,
        public CarbonInterface $sentAt,
        public array $cc = [],
        public array $bcc = [],
        public array $replyTo = [],
        public ?string $inReplyTo = null,
        public array $references = [],
        public ?string $textBody = null,
        public ?string $htmlBody = null,
        public array $headers = [],
        public array $attachments = [],
        public array $labels = [],
        public ?MailboxState $state = null,
        public ?string $providerMessageId = null,
        public ?string $providerThreadId = null,
        public ?int $folderId = null,
        public ?int $imapUid = null,
        public ?int $sizeBytes = null,
        public ?string $rawMime = null,
        public ?CarbonInterface $receivedAt = null,
    ) {}

    public function state(): MailboxState
    {
        return $this->state ?? MailboxState::inbox();
    }

    /**
     * @return array<int, Address>
     */
    public function allRecipients(): array
    {
        return collect([...$this->to, ...$this->cc, ...$this->bcc])
            ->unique(fn (Address $address) => $address->email)
            ->values()
            ->all();
    }

    /**
     * Message-ID values that this message refers to (In-Reply-To + References), normalized.
     *
     * @return array<int, string>
     */
    public function ancestorMessageIds(): array
    {
        return collect([$this->inReplyTo, ...$this->references])
            ->filter()
            ->map(fn (string $id) => trim($id, " \t\r\n<>"))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    public function isAutoSubmitted(): bool
    {
        $auto = strtolower((string) $this->header('auto-submitted'));

        if ($auto !== '' && $auto !== 'no') {
            return true;
        }

        if (strtolower((string) $this->header('x-auto-response-suppress')) !== '') {
            return true;
        }

        if (in_array(strtolower((string) $this->header('precedence')), ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return true;
        }

        return $this->header('list-id') !== null || $this->header('x-autoreply') !== null || $this->header('x-tandem-autoresponse') !== null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        $values = get_object_vars($this);

        return new self(...array_merge($values, $overrides));
    }
}
