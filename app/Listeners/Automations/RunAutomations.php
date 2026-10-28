<?php

namespace App\Listeners\Automations;

use App\Enums\AutomationTrigger;
use App\Enums\ConversationStatus;
use App\Events\Conversations\ConversationAssigned;
use App\Events\Conversations\ConversationMoved;
use App\Events\Conversations\ConversationStatusChanged;
use App\Events\Conversations\ConversationTagged;
use App\Events\Mail\MessageIngested;
use App\Models\Conversation;
use App\Services\Automations\AutomationEngine;
use App\Services\Automations\TriggerEchoGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Maps domain events onto automation triggers and hands them to the engine on the
 * `automations` queue, skipping occurrences the engine produced itself.
 */
class RunAutomations implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'automations';

    public int $tries = 3;

    public function __construct(
        protected AutomationEngine $engine,
        protected TriggerEchoGuard $echoes,
    ) {}

    /**
     * Typed as `object` on purpose: Laravel's listener discovery would otherwise register this
     * class a second time next to the explicit registration in AppServiceProvider.
     *
     * @param  MessageIngested|ConversationAssigned|ConversationMoved|ConversationStatusChanged|ConversationTagged  $event
     */
    public function handle(object $event): void
    {
        $conversation = $this->conversationOf($event);

        if (! $conversation) {
            return;
        }

        if ($conversation->organization_id === null) {
            return;
        }

        foreach ($this->triggersFor($event) as [$trigger, $context]) {
            if ($this->echoes->consume($conversation, $trigger)) {
                continue;
            }

            $this->engine->run($trigger, $conversation, $context + ['source' => 'event']);
        }
    }

    protected function conversationOf(object $event): ?Conversation
    {
        return match (true) {
            $event instanceof MessageIngested,
            $event instanceof ConversationAssigned,
            $event instanceof ConversationMoved,
            $event instanceof ConversationStatusChanged,
            $event instanceof ConversationTagged => $event->conversation,
            default => null,
        };
    }

    /**
     * @return array<int, array{0: AutomationTrigger, 1: array<string, mixed>}>
     */
    protected function triggersFor(object $event): array
    {
        if ($event instanceof MessageIngested) {
            $triggers = [];

            if ($event->message->isInbound()) {
                $triggers[] = [
                    $event->isNewConversation ? AutomationTrigger::ConversationReceived : AutomationTrigger::ReplyReceived,
                    ['message' => $event->message],
                ];
            }

            if ($event->reopened && ! $event->isNewConversation) {
                $triggers[] = [AutomationTrigger::ConversationReopened, ['message' => $event->message]];
            }

            return $triggers;
        }

        if ($event instanceof ConversationAssigned) {
            return $event->assignee
                ? [[AutomationTrigger::ConversationAssigned, ['assignee' => $event->assignee, 'actor' => $event->actor]]]
                : [];
        }

        if ($event instanceof ConversationMoved) {
            return [[AutomationTrigger::ConversationMoved, ['from_inbox' => $event->fromInbox, 'to_inbox' => $event->toInbox, 'actor' => $event->actor]]];
        }

        if ($event instanceof ConversationStatusChanged) {
            if ($event->to === ConversationStatus::Closed) {
                return [[AutomationTrigger::ConversationClosed, ['from' => $event->from->value, 'actor' => $event->actor]]];
            }

            if ($event->from === ConversationStatus::Closed && $event->to === ConversationStatus::Open) {
                return [[AutomationTrigger::ConversationReopened, ['from' => $event->from->value, 'actor' => $event->actor]]];
            }

            return [];
        }

        if ($event instanceof ConversationTagged) {
            return [[AutomationTrigger::TagAdded, ['tag' => $event->tag, 'actor' => $event->actor]]];
        }

        return [];
    }
}
