<?php

namespace App\Services\Automations;

use App\Enums\AutomationTrigger;
use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;

/**
 * Prevents automation actions from re-triggering the engine. When an action emits a domain
 * event (assign, move, tag, close...) it registers one expected "echo"; the listener consumes
 * it and skips that occurrence. Counters live in the cache so it also works across queue workers,
 * and in-memory re-entrancy is tracked separately for synchronous dispatch.
 */
class TriggerEchoGuard
{
    protected const TTL_SECONDS = 600;

    /** @var array<string, true> */
    protected static array $running = [];

    public function expect(Conversation $conversation, AutomationTrigger $trigger): void
    {
        $key = $this->key($conversation, $trigger);

        Cache::add($key, 0, self::TTL_SECONDS);
        Cache::increment($key);
    }

    /**
     * Returns true when this occurrence was produced by the engine itself and must be ignored.
     */
    public function consume(Conversation $conversation, AutomationTrigger $trigger): bool
    {
        $key = $this->key($conversation, $trigger);
        $pending = (int) Cache::get($key, 0);

        if ($pending <= 0) {
            return false;
        }

        if ($pending === 1) {
            Cache::forget($key);
        } else {
            Cache::decrement($key);
        }

        return true;
    }

    /**
     * Mark a (conversation, trigger) pair as currently being processed. Returns false when it already is.
     */
    public function enter(Conversation $conversation, AutomationTrigger $trigger): bool
    {
        $key = $this->key($conversation, $trigger);

        if (isset(self::$running[$key])) {
            return false;
        }

        self::$running[$key] = true;

        return true;
    }

    public function leave(Conversation $conversation, AutomationTrigger $trigger): void
    {
        unset(self::$running[$this->key($conversation, $trigger)]);
    }

    protected function key(Conversation $conversation, AutomationTrigger $trigger): string
    {
        return sprintf('automations:echo:%d:%s', $conversation->id, $trigger->value);
    }
}
