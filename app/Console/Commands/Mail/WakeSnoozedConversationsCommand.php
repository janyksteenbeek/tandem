<?php

namespace App\Console\Commands\Mail;

use App\Enums\Mail\ConversationStatus;
use App\Models\Mail\Conversation;
use App\Services\Mail\Conversations\ConversationActions;
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
