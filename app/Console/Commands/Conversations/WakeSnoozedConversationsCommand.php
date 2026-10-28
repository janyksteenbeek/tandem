<?php

namespace App\Console\Commands\Conversations;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Services\Conversations\ConversationActions;
use Illuminate\Console\Command;

class WakeSnoozedConversationsCommand extends Command
{
    protected $signature = 'conversations:wake-snoozed';

    protected $description = 'Reopen conversations whose snooze has elapsed';

    public function handle(ConversationActions $actions): int
    {
        $count = 0;

        Conversation::query()
            ->where('status', ConversationStatus::Snoozed->value)
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->orderBy('snoozed_until')
            ->limit(500)
            ->get()
            ->each(function (Conversation $conversation) use ($actions, &$count) {
                $actions->wake($conversation);
                $count++;
            });

        $this->info("Woke {$count} conversation(s).");

        return self::SUCCESS;
    }
}
