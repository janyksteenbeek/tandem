<?php

namespace App\Services\Mail\Outbound;

use App\Services\Mail\Data\Address;
use App\Services\Mail\Data\OutgoingAttachment;
use App\Services\Mail\Data\OutgoingMessage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address as MimeAddress;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Converts the canonical OutgoingMessage into a Symfony Email so SMTP, the fallback mailer
 * and Gmail (via raw MIME) all produce byte-identical, standards compliant messages.
 */
class SymfonyEmailBuilder
{
    public function build(OutgoingMessage $message): Email
    {
        $email = (new Email)
            ->from($this->address($message->from))
            ->subject($message->subject)
            ->html($message->htmlBody)
            ->text($message->textBody ?? $this->plainText($message->htmlBody));

        foreach ($message->to as $to) {
            $email->addTo($this->address($to));
        }

        foreach ($message->cc as $cc) {
            $email->addCc($this->address($cc));
        }

        foreach ($message->bcc as $bcc) {
            $email->addBcc($this->address($bcc));
        }

        if ($message->replyTo) {
            $email->replyTo($this->address($message->replyTo));
        }

        $headers = $email->getHeaders();

        if ($message->messageId) {
            $headers->addIdHeader('Message-ID', trim($message->messageId, '<>'));
        }

        if ($message->inReplyTo) {
            $headers->addTextHeader('In-Reply-To', '<'.trim($message->inReplyTo, '<>').'>');
        }

        if ($message->references !== []) {
            $headers->addTextHeader('References', collect($message->references)->map(fn (string $ref) => '<'.trim($ref, '<>').'>')->implode(' '));
        }

        foreach ($message->headers as $name => $value) {
            $headers->addTextHeader($name, $value);
        }

        $headers->addTextHeader('X-Mailer', 'Tandem');

        foreach ($message->attachments as $attachment) {
            $this->attach($email, $attachment);
        }

        return $email;
    }

    protected function attach(Email $email, OutgoingAttachment $attachment): void
    {
        $stream = Storage::disk($attachment->disk)->readStream($attachment->path);

        if ($stream === null) {
            return;
        }

        $part = new DataPart($stream, $attachment->filename, $attachment->mimeType);

        if ($attachment->isInline && $attachment->contentId) {
            $part->asInline();
            $part->setContentId($attachment->contentId);
        }

        $email->addPart($part);
    }

    protected function address(Address $address): MimeAddress
    {
        return new MimeAddress($address->email, $address->name ?? '');
    }

    protected function plainText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>|<\/(p|div|li|tr|h[1-6]|blockquote)>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
