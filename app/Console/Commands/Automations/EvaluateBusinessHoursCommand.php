<?php

namespace App\Console\Commands\Automations;

use App\Enums\AutomationTrigger;
use App\Enums\ConversationStatus;
use App\Models\AutomationRule;
use App\Models\Conversation;
use App\Models\Organization;
use App\Services\Automations\AutomationEngine;
use App\Services\Automations\BusinessHours;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Detects business-hours transitions since the previous run and fires the
 * business_hours_started / business_hours_ended triggers for every open conversation.
 */
class EvaluateBusinessHoursCommand extends Command
{
    public const MAX_CONVERSATIONS_PER_ORGANIZATION = 500;

    protected $signature = 'automations:business-hours';

    protected $description = 'Run business-hours automation triggers for organizations whose schedule changed state';

    public function handle(BusinessHours $businessHours, AutomationEngine $engine): int
    {
        $triggers = [AutomationTrigger::BusinessHoursStarted->value, AutomationTrigger::BusinessHoursEnded->value];
        $now = CarbonImmutable::instance(now());

        $organizations = Organization::query()
            ->whereNotNull('business_hours')
            ->whereHas('automationRules', fn (Builder $q) => $q->where('is_active', true)->whereIn('trigger', $triggers))
            ->get();

        $fired = 0;

        foreach ($organizations as $organization) {
            if (! $businessHours->hasSchedule($organization)) {
                continue;
            }

            $key = sprintf('automations:business-hours:%d', $organization->id);
            $previous = Cache::get($key);

            Cache::put($key, $now->getTimestamp(), now()->addDays(2));

            if (! is_numeric($previous)) {
                continue;
            }

            $transitions = $businessHours->transitionsBetween($organization, CarbonImmutable::createFromTimestamp((int) $previous), $now);

            if ($transitions === []) {
                continue;
            }

            $latest = end($transitions);
            $trigger = $latest['open'] ? AutomationTrigger::BusinessHoursStarted : AutomationTrigger::BusinessHoursEnded;

            $fired += $this->fire($engine, $organization, $trigger, $latest['at']);
        }

        $this->info(sprintf('Evaluated %d organization(s); %d conversation run(s) fired.', $organizations->count(), $fired));

        return self::SUCCESS;
    }

    protected function fire(AutomationEngine $engine, Organization $organization, AutomationTrigger $trigger, CarbonImmutable $at): int
    {
        $rules = AutomationRule::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->where('trigger', $trigger->value)
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $inboxIds = $rules->pluck('inbox_id');

        $conversations = Conversation::query()
            ->where('organization_id', $organization->id)
            ->where('status', ConversationStatus::Open->value)
            ->where('is_trashed', false)
            ->where('is_spam', false)
            ->when(! $inboxIds->contains(null), fn (Builder $q) => $q->whereIn('inbox_id', $inboxIds->filter()->unique()->all()))
            ->orderByDesc('last_activity_at')
            ->limit(self::MAX_CONVERSATIONS_PER_ORGANIZATION)
            ->get();

        foreach ($conversations as $conversation) {
            $engine->run($trigger, $conversation, ['source' => 'schedule', 'transition_at' => $at->toIso8601String()]);
        }

        return $conversations->count();
    }
}
