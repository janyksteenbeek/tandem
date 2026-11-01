<?php

namespace App\Services\Mail;

/**
 * A parsed mailto: link (RFC 6068): the addresses after the scheme plus the
 * to, cc, bcc, subject and body header fields from the query string.
 *
 * Anything that is not a plausible address is dropped rather than rejected,
 * so a link with one typo still opens the composer with the rest filled in.
 */
final readonly class MailtoLink
{
    private const MAX_RECIPIENTS = 50;

    private const MAX_SUBJECT = 255;

    private const MAX_BODY = 20000;

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    public function __construct(
        public array $to = [],
        public array $cc = [],
        public array $bcc = [],
        public ?string $subject = null,
        public ?string $body = null,
    ) {}

    /**
     * Returns null when the value is not a mailto: link at all.
     */
    public static function parse(string $url): ?self
    {
        $url = trim($url);

        if (! str_starts_with(strtolower($url), 'mailto:')) {
            return null;
        }

        $rest = substr($url, strlen('mailto:'));
        [$addressPart, $query] = array_pad(explode('?', $rest, 2), 2, '');

        $headers = [];

        foreach (array_filter(explode('&', $query)) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = strtolower(rawurldecode($name));

            if (! in_array($name, ['to', 'cc', 'bcc', 'subject', 'body'], true)) {
                continue;
            }

            $headers[$name] = isset($headers[$name]) && in_array($name, ['to', 'cc', 'bcc'], true)
                ? $headers[$name].','.rawurldecode($value)
                : rawurldecode($value);
        }

        $to = rawurldecode($addressPart).(isset($headers['to']) ? ','.$headers['to'] : '');

        return new self(
            to: self::addresses($to),
            cc: self::addresses($headers['cc'] ?? ''),
            bcc: self::addresses($headers['bcc'] ?? ''),
            subject: self::text($headers['subject'] ?? null, self::MAX_SUBJECT),
            body: self::text($headers['body'] ?? null, self::MAX_BODY, multiline: true),
        );
    }

    public function isEmpty(): bool
    {
        return $this->to === [] && $this->cc === [] && $this->bcc === []
            && $this->subject === null && $this->body === null;
    }

    /**
     * The shape the composer accepts: addresses as objects and the plain-text
     * body as paragraphs the editor can load.
     *
     * @return array{
     *     to: list<array{name: null, email: string}>,
     *     cc: list<array{name: null, email: string}>,
     *     bcc: list<array{name: null, email: string}>,
     *     subject: string|null,
     *     html_body: string|null
     * }
     */
    public function toPrefill(): array
    {
        $wrap = fn (array $emails): array => array_map(
            fn (string $email) => ['name' => null, 'email' => $email],
            $emails,
        );

        return [
            'to' => $wrap($this->to),
            'cc' => $wrap($this->cc),
            'bcc' => $wrap($this->bcc),
            'subject' => $this->subject,
            'html_body' => $this->body === null ? null : self::toHtml($this->body),
        ];
    }

    /**
     * @return list<string>
     */
    private static function addresses(string $list): array
    {
        $emails = [];

        foreach (preg_split('/[,;]/', $list) ?: [] as $candidate) {
            $candidate = trim($candidate);

            if (preg_match('/<([^>]+)>/', $candidate, $matches)) {
                $candidate = trim($matches[1]);
            }

            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $emails[strtolower($candidate)] ??= $candidate;
        }

        return array_slice(array_values($emails), 0, self::MAX_RECIPIENTS);
    }

    private static function text(?string $value, int $max, bool $multiline = false): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\r\n", "\n", $value);
        $value = $multiline
            ? preg_replace('/[^\P{C}\n\t]+/u', '', $value)
            : preg_replace('/\p{C}+/u', ' ', $value);
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * Blank lines separate paragraphs; single newlines become line breaks.
     */
    private static function toHtml(string $body): string
    {
        $paragraphs = preg_split('/\n{2,}/', $body) ?: [];

        return implode('', array_map(
            fn (string $paragraph) => '<p>'.str_replace("\n", '<br>', e($paragraph)).'</p>',
            $paragraphs,
        ));
    }
}
