<?php

namespace App\Services\Mail\Ingestion;

use App\Services\Mail\Data\Address;
use App\Services\Mail\Data\IncomingAttachment;
use App\Services\Mail\Data\IncomingMessage;
use App\Services\Mail\Data\MailboxState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Turns raw RFC 5322 MIME into the canonical IncomingMessage. Used by every adapter so
 * threading, bodies and attachments behave identically regardless of provider.
 */
class MimeMessageParser
{
    public function __construct(protected MailMimeParser $parser) {}

    /**
     * @param  array<string, mixed>  $context  Provider specific extras merged into the DTO.
     */
    public function parse(string $rawMime, array $context = []): IncomingMessage
    {
        $message = $this->parser->parse($rawMime, false);

        $headers = $this->headers($message);
        $references = $this->references($message);

        $sentAt = $this->date($message) ?? CarbonImmutable::now();

        return new IncomingMessage(
            messageId: $this->normalizeId($message->getHeaderValue(HeaderConsts::MESSAGE_ID)),
            from: $this->addresses($message, HeaderConsts::FROM)[0] ?? $this->addresses($message, HeaderConsts::SENDER)[0] ?? null,
            to: $this->addresses($message, HeaderConsts::TO),
            subject: $this->subject($message),
            sentAt: $sentAt,
            cc: $this->addresses($message, HeaderConsts::CC),
            bcc: $this->addresses($message, HeaderConsts::BCC),
            replyTo: $this->addresses($message, HeaderConsts::REPLY_TO),
            inReplyTo: $this->normalizeId($message->getHeaderValue(HeaderConsts::IN_REPLY_TO)),
            references: $references,
            textBody: $this->nullIfBlank($message->getTextContent()),
            htmlBody: $this->nullIfBlank($message->getHtmlContent()),
            headers: $headers,
            attachments: $this->attachments($message),
            labels: $context['labels'] ?? [],
            state: $context['state'] ?? MailboxState::inbox(),
            providerMessageId: $context['providerMessageId'] ?? null,
            providerThreadId: $context['providerThreadId'] ?? null,
            folderId: $context['folderId'] ?? null,
            imapUid: $context['imapUid'] ?? null,
            sizeBytes: $context['sizeBytes'] ?? strlen($rawMime),
            rawMime: ($context['keepRaw'] ?? true) ? $rawMime : null,
            receivedAt: $context['receivedAt'] ?? null,
        );
    }

    /**
     * @return array<int, Address>
     */
    protected function addresses(IMessage $message, string $header): array
    {
        $h = $message->getHeader($header);

        if (! $h instanceof AddressHeader) {
            return Address::parseList($h?->getValue());
        }

        return collect($h->getAddresses())
            ->map(fn ($part) => filter_var($part->getEmail(), FILTER_VALIDATE_EMAIL)
                ? new Address($part->getEmail(), $this->nullIfBlank($part->getName()))
                : null)
            ->filter()
            ->unique(fn (Address $address) => $address->email)
            ->values()
            ->all();
    }

    protected function date(IMessage $message): ?CarbonImmutable
    {
        $header = $message->getHeader(HeaderConsts::DATE);

        if ($header instanceof DateHeader && $header->getDateTimeImmutable()) {
            return CarbonImmutable::instance($header->getDateTimeImmutable())->utc();
        }

        $raw = $message->getHeaderValue(HeaderConsts::DATE);

        if ($raw) {
            try {
                return CarbonImmutable::parse($raw)->utc();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    protected function subject(IMessage $message): ?string
    {
        $subject = $message->getSubject();

        return $subject === null ? null : Str::limit(trim(preg_replace('/\s+/', ' ', $subject) ?? ''), 250, '');
    }

    /**
     * @return array<int, string>
     */
    protected function references(IMessage $message): array
    {
        $raw = $message->getHeaderValue(HeaderConsts::REFERENCES);

        if (! $raw) {
            return [];
        }

        preg_match_all('/<([^>]+)>/', $raw, $matches);

        return collect($matches[1])
            ->map(fn (string $id) => trim($id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    protected function headers(IMessage $message): array
    {
        $headers = [];

        foreach ($message->getAllHeaders() as $header) {
            $name = strtolower($header->getName());
            $value = Str::limit($header->getRawValue(), 2000, '');

            if (in_array($name, ['received', 'dkim-signature', 'arc-seal', 'arc-message-signature', 'arc-authentication-results', 'x-google-dkim-signature'], true)) {
                continue;
            }

            if (isset($headers[$name])) {
                $headers[$name] = array_merge((array) $headers[$name], [$value]);
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * @return array<int, IncomingAttachment>
     */
    protected function attachments(IMessage $message): array
    {
        $attachments = [];

        foreach ($message->getAllAttachmentParts() as $index => $part) {
            $attachments[] = $this->attachment($part, $index);
        }

        return $attachments;
    }

    protected function attachment(IMessagePart $part, int $index): IncomingAttachment
    {
        $content = $part->getBinaryContentStream()?->getContents() ?? '';
        $mime = strtolower($part->getContentType('application/octet-stream'));
        $filename = $part->getFilename() ?: $this->fallbackFilename($mime, $index);
        $contentId = $part->getContentId();
        $disposition = strtolower((string) $part->getContentDisposition('attachment'));

        return new IncomingAttachment(
            filename: Str::limit(basename(str_replace(['\\', "\0"], '', $filename)), 200, ''),
            mimeType: $mime,
            size: strlen($content),
            content: $content,
            contentId: $contentId ? trim($contentId, '<>') : null,
            isInline: $disposition === 'inline' || ($contentId !== null && Str::startsWith($mime, 'image/')),
        );
    }

    protected function fallbackFilename(string $mime, int $index): string
    {
        $extension = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'calendar') => 'ics',
            str_contains($mime, 'rfc822') => 'eml',
            default => 'bin',
        };

        return sprintf('attachment-%d.%s', $index + 1, $extension);
    }

    protected function normalizeId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $id = trim($id);

        if (preg_match('/<([^>]+)>/', $id, $m)) {
            $id = $m[1];
        }

        $id = trim($id, " \t\r\n<>");

        return $id === '' ? null : Str::limit($id, 990, '');
    }

    protected function nullIfBlank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
