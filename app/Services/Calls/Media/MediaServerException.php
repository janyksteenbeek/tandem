<?php

namespace App\Services\Calls\Media;

use RuntimeException;
use Throwable;

class MediaServerException extends RuntimeException
{
    public function __construct(string $message, public int $status = 0, public string $twirpCode = '', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The room or participant is already gone, which most callers can treat as done.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404 || $this->twirpCode === 'not_found';
    }
}
