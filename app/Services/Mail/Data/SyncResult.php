<?php

namespace App\Services\Mail\Data;

final class SyncResult
{
    public int $fetched = 0;

    public int $created = 0;

    public int $updated = 0;

    public int $deleted = 0;

    public int $skipped = 0;

    /** @var array<int, string> */
    public array $errors = [];

    public bool $hasMore = false;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fetched' => $this->fetched,
            'created' => $this->created,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'skipped' => $this->skipped,
            'errors' => array_slice($this->errors, 0, 20),
            'has_more' => $this->hasMore,
        ];
    }

    public function merge(self $other): self
    {
        $this->fetched += $other->fetched;
        $this->created += $other->created;
        $this->updated += $other->updated;
        $this->deleted += $other->deleted;
        $this->skipped += $other->skipped;
        $this->errors = [...$this->errors, ...$other->errors];
        $this->hasMore = $this->hasMore || $other->hasMore;

        return $this;
    }
}
