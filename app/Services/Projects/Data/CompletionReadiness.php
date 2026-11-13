<?php

namespace App\Services\Projects\Data;

use App\Enums\Projects\StatusCategory;

/**
 * What the workstreams of an issue say about its overall status.
 */
final readonly class CompletionReadiness
{
    public function __construct(
        public int $total,
        public int $required,
        public int $requiredCompleted,
        public int $requiredCanceled,
        public int $completed,
        public int $started,
        public int $blocked,
        public bool $ready,
        public ?StatusCategory $suggested,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'required' => $this->required,
            'required_completed' => $this->requiredCompleted,
            'required_canceled' => $this->requiredCanceled,
            'completed' => $this->completed,
            'started' => $this->started,
            'blocked' => $this->blocked,
            'ready' => $this->ready,
            'suggested' => $this->suggested?->value,
        ];
    }
}
