<?php

namespace App\Services\Mail\Providers\Imap\Data;

/**
 * One entry of an IMAP LIST response.
 */
final readonly class RemoteFolder
{
    /**
     * @param  array<int, string>  $flags  Raw LIST attributes, e.g. ["\HasNoChildren", "\Sent"].
     */
    public function __construct(
        public string $path,
        public string $delimiter = '/',
        public array $flags = [],
    ) {}

    public function hasFlag(string $flag): bool
    {
        $wanted = strtolower(ltrim($flag, '\\'));

        foreach ($this->flags as $candidate) {
            if (strtolower(ltrim($candidate, '\\')) === $wanted) {
                return true;
            }
        }

        return false;
    }

    public function isSelectable(): bool
    {
        return ! $this->hasFlag('\Noselect') && ! $this->hasFlag('\NonExistent');
    }

    /**
     * Last path segment, used as the display name.
     */
    public function name(): string
    {
        $parts = $this->delimiter !== '' ? explode($this->delimiter, $this->path) : [$this->path];

        return (string) end($parts);
    }
}
