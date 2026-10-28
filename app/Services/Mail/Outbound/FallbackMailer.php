<?php

namespace App\Services\Mail\Outbound;

use App\Models\MailAccount;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Data\SentMessageResult;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Sends through an organization's configured SMTP settings, or the application's default
 * outbound mailer. Used for forwarding-only inboxes and automation autoresponders.
 */
class FallbackMailer
{
    public function __construct(protected SymfonyEmailBuilder $builder) {}

    public function send(?MailAccount $account, OutgoingMessage $message): SentMessageResult
    {
        $messageId = $message->messageId ?? $this->generateMessageId($message->from->email);
        $email = $this->builder->build($message->messageId ? $message : new OutgoingMessage(
            from: $message->from,
            to: $message->to,
            subject: $message->subject,
            htmlBody: $message->htmlBody,
            textBody: $message->textBody,
            cc: $message->cc,
            bcc: $message->bcc,
            replyTo: $message->replyTo,
            messageId: $messageId,
            inReplyTo: $message->inReplyTo,
            references: $message->references,
            attachments: $message->attachments,
            headers: $message->headers,
        ));

        $this->transportFor($account)->send($email);

        return new SentMessageResult(messageId: $messageId);
    }

    protected function transportFor(?MailAccount $account): TransportInterface
    {
        $settings = $account?->organization?->outbound_mail_settings;

        if (is_array($settings) && ! empty($settings['host'])) {
            return Transport::fromDsn(sprintf(
                '%s://%s:%s@%s:%d',
                ($settings['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
                rawurlencode((string) ($settings['username'] ?? '')),
                rawurlencode((string) ($settings['password'] ?? '')),
                $settings['host'],
                (int) ($settings['port'] ?? 587),
            ));
        }

        return Mail::mailer(config('tandem.outbound.mailer'))->getSymfonyTransport();
    }

    public function generateMessageId(string $fromEmail): string
    {
        $domain = Str::after($fromEmail, '@') ?: 'tandem.local';

        return Str::uuid().'@'.$domain;
    }
}
