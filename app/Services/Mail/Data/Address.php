<?php

namespace App\Services\Mail\Data;

use Illuminate\Support\Str;

final readonly class Address
{
    public string $email;

    public function __construct(string $email, public ?string $name = null)
    {
        $this->email = Str::lower(trim($email, " \t\r\n<>"));
    }

    public static function parse(string $raw): ?self
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>\s*$/', $raw, $m)) {
            $name = trim($m[1], " \t\"'");

            return filter_var(trim($m[2]), FILTER_VALIDATE_EMAIL) ? new self($m[2], $name !== '' ? $name : null) : null;
        }

        return filter_var($raw, FILTER_VALIDATE_EMAIL) ? new self($raw) : null;
    }

    /**
     * Parse a comma separated header list.
     *
     * @return array<int, self>
     */
    public static function parseList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $raw) ?: [];

        return collect($parts)
            ->map(fn (string $part) => self::parse($part))
            ->filter()
            ->unique(fn (self $address) => $address->email)
            ->values()
            ->all();
    }

    /**
     * @param  array{name?: string|null, email?: string|null}|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if (! $data || empty($data['email'])) {
            return null;
        }

        return new self($data['email'], $data['name'] ?? null);
    }

    /**
     * @param  array<int, array{name?: string|null, email?: string|null}>|null  $items
     * @return array<int, self>
     */
    public static function fromArrayList(?array $items): array
    {
        return collect($items ?? [])->map(fn (array $item) => self::fromArray($item))->filter()->values()->all();
    }

    /**
     * @return array{name: string|null, email: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }

    public function displayName(): string
    {
        return $this->name ?: Str::before($this->email, '@');
    }

    public function toString(): string
    {
        return $this->name ? sprintf('%s <%s>', $this->name, $this->email) : $this->email;
    }

    public function is(string|self|null $other): bool
    {
        if ($other === null) {
            return false;
        }

        $email = $other instanceof self ? $other->email : Str::lower(trim($other));

        return $this->email === $email;
    }
}
