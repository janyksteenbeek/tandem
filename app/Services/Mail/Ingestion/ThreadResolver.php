<?php

namespace App\Services\Mail\Ingestion;

use App\Models\Conversation;
use App\Models\MailAccount;
use App\Models\Message;
use App\Services\Mail\Data\IncomingMessage;
use Illuminate\Support\Str;

/**
 * Finds the conversation a message belongs to, in order of confidence:
 *  1. provider thread id (Gmail threadId),
 *  2. In-Reply-To / References pointing at a message we already have in this inbox,
 *  3. same normalized subject with an overlapping participant inside a recent window.
 */
class ThreadResolver
{
    public function resolve(MailAccount $account, IncomingMessage $incoming): ?Conversation
    {
        if ($incoming->providerThreadId) {
            $byThread = Conversation::withTrashed()
                ->where('mail_account_id', $account->id)
                ->where('provider_thread_id', $incoming->providerThreadId)
                ->first();

            if ($byThread) {
                return $byThread;
            }
        }

        $ancestors = $incoming->ancestorMessageIds();

        if ($ancestors !== []) {
            $hashes = array_map(fn (string $id) => Message::hashMessageId($id), $ancestors);

            $parent = Message::query()
                ->whereIn('message_id_hash', $hashes)
                ->whereHas('conversation', fn ($q) => $q->where('inbox_id', $account->inbox_id))
                ->latest('sent_at')
                ->first();

            if ($parent) {
                return $parent->conversation;
            }
        }

        return $this->resolveBySubject($account, $incoming);
    }

    protected function resolveBySubject(MailAccount $account, IncomingMessage $incoming): ?Conversation
    {
        $subject = $incoming->subject ?? '';

        if (! preg_match('/^\s*(re|fw|fwd|aw|wg|tr|sv|vs|antw)\s*(\[\d+\])?\s*:/i', $subject)) {
            return null;
        }

        $normalized = Conversation::normalizeSubject($subject);

        if ($normalized === '') {
            return null;
        }

        $participants = collect([$incoming->from, ...$incoming->allRecipients()])
            ->filter()
            ->map(fn ($address) => Str::lower($address->email))
            ->reject(fn (string $email) => $email === $account->email_address)
            ->unique()
            ->values();

        if ($participants->isEmpty()) {
            return null;
        }

        $candidates = Conversation::query()
            ->where('inbox_id', $account->inbox_id)
            ->where('normalized_subject', $normalized)
            ->where('last_message_at', '>=', $incoming->sentAt->copy()->subDays(14))
            ->orderByDesc('last_message_at')
            ->limit(10)
            ->get();

        foreach ($candidates as $candidate) {
            $existing = collect($candidate->participants ?? [])->pluck('email')->map(fn ($e) => Str::lower((string) $e));

            if ($existing->intersect($participants)->isNotEmpty()) {
                return $candidate;
            }
        }

        return null;
    }
}
