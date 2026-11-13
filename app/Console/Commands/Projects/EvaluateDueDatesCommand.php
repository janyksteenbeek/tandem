<?php

namespace App\Console\Commands\Projects;

use App\Enums\Automations\AutomationTrigger;
use App\Models\Automations\AutomationRule;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Notifications\Projects\IssueDueNotification;
use App\Services\Automations\AutomationEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Hourly: fires the due-date automation triggers at most once per rule, issue and day, and sends
 * the built-in "due tomorrow" / "overdue" notifications once per issue per day.
 */
class EvaluateDueDatesCommand extends Command
{
    protected $signature = 'projects:due-dates {--window=14 : How many days ahead "approaching" looks}';

    protected $description = 'Evaluate due-date automation triggers and send due/overdue reminders';

    public function handle(AutomationEngine $engine): int
    {
        $window = max(1, (int) $this->option('window'));
        $runs = 0;

        Organization::query()->whereHas('automationRules', fn ($q) => $q->where('is_active', true)->whereIn('trigger', [AutomationTrigger::DueDateApproaching->value, AutomationTrigger::IssueOverdue->value]))
            ->get()
            ->each(function (Organization $organization) use ($engine, $window, &$runs) {
                $today = now($organization->timezone ?: 'UTC')->toDateString();

                $rules = AutomationRule::query()->where('organization_id', $organization->id)->where('is_active', true)->whereIn('trigger', [AutomationTrigger::DueDateApproaching->value, AutomationTrigger::IssueOverdue->value])->get();

                if ($rules->where('trigger', AutomationTrigger::DueDateApproaching)->isNotEmpty()) {
                    Issue::query()->where('organization_id', $organization->id)->whereNotIn('status_category', ['completed', 'canceled'])
                        ->whereBetween('due_date', [$today, now($organization->timezone ?: 'UTC')->addDays($window)->toDateString()])
                        ->limit(500)->get()
                        ->each(function (Issue $issue) use ($engine, $rules, $organization, &$runs) {
                            foreach ($rules->where('trigger', AutomationTrigger::DueDateApproaching) as $rule) {
                                if (! $engine->ranToday($rule, $issue, AutomationTrigger::DueDateApproaching, $organization->timezone ?: 'UTC')) {
                                    $engine->run(AutomationTrigger::DueDateApproaching, $issue, ['source' => 'schedule']);
                                    $runs++;
                                }
                            }
                        });
                }

                if ($rules->where('trigger', AutomationTrigger::IssueOverdue)->isNotEmpty()) {
                    Issue::query()->where('organization_id', $organization->id)->whereNotIn('status_category', ['completed', 'canceled'])
                        ->where('due_date', '<', $today)
                        ->limit(500)->get()
                        ->each(function (Issue $issue) use ($engine, $rules, $organization, &$runs) {
                            foreach ($rules->where('trigger', AutomationTrigger::IssueOverdue) as $rule) {
                                if (! $engine->ranToday($rule, $issue, AutomationTrigger::IssueOverdue, $organization->timezone ?: 'UTC')) {
                                    $engine->run(AutomationTrigger::IssueOverdue, $issue, ['source' => 'schedule']);
                                    $runs++;
                                }
                            }
                        });
                }
            });

        $reminders = $this->remind();

        $this->info("Due dates: {$runs} automation runs, {$reminders} reminders.");

        return self::SUCCESS;
    }

    /**
     * Built-in reminders to owners and assignees: due tomorrow, and the first day overdue.
     */
    protected function remind(): int
    {
        $sent = 0;
        $tomorrow = now()->addDay()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        Issue::query()->whereNotIn('status_category', ['completed', 'canceled'])->whereIn('due_date', [$tomorrow, $yesterday])
            ->with(['owner', 'workstreams.assignees'])
            ->limit(2000)->get()
            ->each(function (Issue $issue) use ($tomorrow, &$sent) {
                $recipients = collect([$issue->owner])->merge($issue->workstreams->flatMap->assignees)->filter()->unique('id');

                if ($recipients->isEmpty()) {
                    return;
                }

                Notification::send($recipients, new IssueDueNotification($issue, $issue->due_date?->toDateString() !== $tomorrow));
                $sent += $recipients->count();
            });

        return $sent;
    }
}
