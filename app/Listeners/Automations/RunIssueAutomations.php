<?php

namespace App\Listeners\Automations;

use App\Enums\Automations\AutomationTrigger;
use App\Enums\Projects\StatusCategory;
use App\Events\Projects\CycleCompleted;
use App\Events\Projects\CycleStarted;
use App\Events\Projects\DependencyResolved;
use App\Events\Projects\IssueAssigned;
use App\Events\Projects\IssueCommentPosted;
use App\Events\Projects\IssueCreated;
use App\Events\Projects\IssueStatusChanged;
use App\Events\Projects\TeamAddedToIssue;
use App\Events\Projects\WorkstreamStatusChanged;
use App\Models\Projects\Issue;
use App\Services\Automations\AutomationEngine;
use App\Services\Automations\TriggerEchoGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Maps project domain events onto automation triggers, on the `automations` queue, skipping
 * occurrences the engine produced itself. Registered explicitly in AppServiceProvider.
 */
class RunIssueAutomations implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'automations';

    public int $tries = 3;

    public function __construct(protected AutomationEngine $engine, protected TriggerEchoGuard $echoes) {}

    /**
     * Typed as `object` on purpose: listener discovery would otherwise register this class a
     * second time next to the explicit registration.
     */
    public function handle(object $event): void
    {
        if ($event instanceof CycleStarted || $event instanceof CycleCompleted) {
            $this->cycle($event);

            return;
        }

        $issue = $this->issueOf($event);

        if (! $issue) {
            return;
        }

        foreach ($this->triggersFor($event) as [$trigger, $context]) {
            if ($this->echoes->consume($issue, $trigger)) {
                continue;
            }

            $this->engine->run($trigger, $issue, $context + ['source' => 'event']);
        }
    }

    protected function issueOf(object $event): ?Issue
    {
        return match (true) {
            $event instanceof IssueCreated,
            $event instanceof IssueAssigned,
            $event instanceof TeamAddedToIssue,
            $event instanceof WorkstreamStatusChanged,
            $event instanceof IssueStatusChanged,
            $event instanceof DependencyResolved,
            $event instanceof IssueCommentPosted => $event->issue,
            default => null,
        };
    }

    /**
     * @return array<int, array{0: AutomationTrigger, 1: array<string, mixed>}>
     */
    protected function triggersFor(object $event): array
    {
        if ($event instanceof IssueCreated) {
            return [[AutomationTrigger::IssueCreated, ['actor' => $event->actor, 'created_source' => $event->source]]];
        }

        if ($event instanceof IssueAssigned) {
            return [[AutomationTrigger::IssueAssigned, ['workstream' => $event->workstream, 'assignee' => $event->assignee, 'actor' => $event->actor]]];
        }

        if ($event instanceof TeamAddedToIssue) {
            return [[AutomationTrigger::TeamAdded, ['workstream' => $event->workstream, 'actor' => $event->actor]]];
        }

        if ($event instanceof WorkstreamStatusChanged) {
            return [[AutomationTrigger::WorkstreamStatusChanged, ['workstream' => $event->workstream, 'from' => $event->from, 'to' => $event->to, 'actor' => $event->actor]]];
        }

        if ($event instanceof IssueStatusChanged) {
            $triggers = [[AutomationTrigger::OverallStatusChanged, ['from' => $event->from, 'to' => $event->to, 'actor' => $event->actor]]];

            if ($event->to->category === StatusCategory::Completed) {
                $triggers[] = [AutomationTrigger::IssueCompleted, ['from' => $event->from, 'to' => $event->to, 'actor' => $event->actor]];
            }

            return $triggers;
        }

        if ($event instanceof DependencyResolved) {
            return [[AutomationTrigger::DependencyResolved, ['workstream' => $event->workstream, 'resolved_by' => $event->resolvedBy]]];
        }

        if ($event instanceof IssueCommentPosted) {
            return [[AutomationTrigger::CommentAdded, ['comment' => $event->comment, 'actor' => $event->author]]];
        }

        return [];
    }

    /**
     * Cycle triggers fire once per issue that has a workstream in the cycle, capped so one cycle
     * cannot flood the queue.
     */
    protected function cycle(CycleStarted|CycleCompleted $event): void
    {
        $trigger = $event instanceof CycleStarted ? AutomationTrigger::CycleStarted : AutomationTrigger::CycleCompleted;

        Issue::query()
            ->whereHas('workstreams', fn ($q) => $q->where('cycle_id', $event->cycle->id))
            ->limit(500)
            ->get()
            ->each(fn (Issue $issue) => $this->engine->run($trigger, $issue, ['cycle' => $event->cycle, 'source' => 'event']));
    }
}
