<?php

namespace App\Services\Automations;

use App\Enums\Automations\AutomationSubjectType;
use App\Enums\Automations\AutomationTrigger;
use App\Enums\Mail\ConversationEventType;
use App\Enums\Projects\IssueActivityType;
use App\Models\Automations\AutomationRule;
use App\Models\Automations\AutomationRun;
use App\Models\Mail\Conversation;
use App\Models\Mail\Message;
use App\Models\Projects\Issue;
use App\Services\Projects\IssueActions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the organization's automation rules for one trigger against one subject (a conversation
 * or an issue). Each rule is evaluated and executed in isolation: a broken rule is logged and
 * audited but never stops the next one, and nothing ever propagates back into the caller.
 */
class AutomationEngine
{
    public const MAX_DEPTH = 3;

    protected static int $depth = 0;

    public function __construct(
        protected ConditionEvaluator $evaluator,
        protected ActionExecutor $executor,
        protected TriggerEchoGuard $echoes,
    ) {}

    /**
     * @param  array{message?: Message|null, source?: string}  $context
     */
    public function run(AutomationTrigger $trigger, Conversation|Issue $subject, array $context = []): void
    {
        if ($subject->organization_id === null) {
            return;
        }

        if ($trigger->subjectType() !== AutomationSubjectType::of($subject)) {
            return;
        }

        if (self::$depth >= self::MAX_DEPTH) {
            Log::info('Automation depth limit reached, skipping', ['trigger' => $trigger->value, 'subject' => $subject->getMorphClass(), 'id' => $subject->getKey()]);

            return;
        }

        if (! $this->echoes->enter($subject, $trigger)) {
            return;
        }

        self::$depth++;

        try {
            if ($subject instanceof Conversation) {
                $subject->loadMissing(['inbox', 'organization']);
            } else {
                $subject->loadMissing(['organization', 'workstreams', 'status', 'product', 'project']);
            }

            foreach ($this->rulesFor($trigger, $subject) as $rule) {
                $this->runRule($rule, $trigger, $subject, $context);
            }
        } catch (Throwable $e) {
            Log::error('Automation engine failed', [
                'trigger' => $trigger->value,
                'subject' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'error' => $e->getMessage(),
            ]);
        } finally {
            self::$depth--;
            $this->echoes->leave($subject, $trigger);
        }
    }

    /**
     * Active rules of the subject's organization for a trigger, scoped to its inbox (conversations)
     * or product/team (issues).
     *
     * @return Collection<int, AutomationRule>
     */
    public function rulesFor(AutomationTrigger $trigger, Conversation|Issue $subject): Collection
    {
        $query = AutomationRule::query()
            ->where('organization_id', $subject->organization_id)
            ->where('subject_type', $trigger->subjectType()->value)
            ->where('trigger', $trigger->value)
            ->where('is_active', true);

        if ($subject instanceof Conversation) {
            $query->where(fn (Builder $q) => $q->whereNull('inbox_id')->orWhere('inbox_id', $subject->inbox_id));
        } else {
            $teamIds = $subject->workstreams->pluck('team_id')->push($subject->lead_team_id)->filter()->unique()->all();

            $query->where(fn (Builder $q) => $q->whereNull('product_id')->orWhere('product_id', $subject->product_id))
                ->where(fn (Builder $q) => $teamIds === [] ? $q->whereNull('team_id') : $q->whereNull('team_id')->orWhereIn('team_id', $teamIds));
        }

        return $query->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Evaluate a rule without executing anything. Used by the dry-run endpoint.
     *
     * @param  array<int, array{field?: mixed, operator?: mixed, value?: mixed}>  $conditions
     * @param  array{message?: Message|null}  $context
     * @return array{matched: bool, match: string, conditions: array<int, array<string, mixed>>}
     */
    public function dryRun(array $conditions, string $match, Conversation|Issue $subject, array $context = []): array
    {
        if ($subject instanceof Conversation) {
            $subject->loadMissing(['inbox', 'organization']);
        }

        return $this->evaluator->evaluate($conditions, $match, $subject, $context);
    }

    /**
     * @param  array{message?: Message|null, source?: string}  $context
     */
    protected function runRule(AutomationRule $rule, AutomationTrigger $trigger, Conversation|Issue $subject, array $context): void
    {
        $performed = [];

        try {
            $evaluation = $this->evaluator->evaluate($rule->conditions ?? [], $rule->match, $subject, $context);

            if (! $evaluation['matched']) {
                $this->record($rule, $subject, $trigger, 'skipped', ['reason' => 'conditions_not_met', 'conditions' => $evaluation['conditions'], 'match' => $evaluation['match']]);

                return;
            }

            if ($this->withinRuleCooldown($rule, $subject)) {
                $this->record($rule, $subject, $trigger, 'skipped', ['reason' => 'cooldown', 'conditions' => $evaluation['conditions'], 'match' => $evaluation['match']]);

                return;
            }

            DB::transaction(function () use ($rule, $trigger, $subject, $context, $evaluation, &$performed) {
                foreach ($rule->actions ?? [] as $action) {
                    $performed[] = $this->executor->execute($rule, $action, $subject, $context);
                }

                $rule->forceFill(['run_count' => $rule->run_count + 1, 'last_run_at' => now()])->save();

                $this->record($rule, $subject, $trigger, 'matched', [
                    'conditions' => $evaluation['conditions'],
                    'match' => $evaluation['match'],
                    'actions' => $performed,
                ]);

                $this->writeActivity($rule, $trigger, $subject, $performed);
            });
        } catch (Throwable $e) {
            Log::warning('Automation rule failed', [
                'rule_id' => $rule->id,
                'subject' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'trigger' => $trigger->value,
                'error' => $e->getMessage(),
            ]);

            try {
                $this->record($rule, $subject, $trigger, 'failed', [
                    'error' => Str::limit($e->getMessage(), 1000),
                    'exception' => $e::class,
                    'actions' => $performed,
                ]);
            } catch (Throwable $inner) {
                Log::error('Automation audit write failed', ['rule_id' => $rule->id, 'error' => $inner->getMessage()]);
            }

            $subject->refresh();
        }
    }

    /**
     * The "automation ran" line in the subject's timeline.
     *
     * @param  array<int, array<string, mixed>>  $performed
     */
    protected function writeActivity(AutomationRule $rule, AutomationTrigger $trigger, Conversation|Issue $subject, array $performed): void
    {
        $payload = [
            'rule_id' => $rule->id,
            'rule_uuid' => $rule->uuid,
            'rule_name' => $rule->name,
            'trigger' => $trigger->value,
            'actions' => $performed,
        ];

        if ($subject instanceof Conversation) {
            $subject->events()->create(['type' => ConversationEventType::AutomationRan, 'actor_id' => null, 'payload' => $payload]);

            return;
        }

        app(IssueActions::class)->record($subject, IssueActivityType::AutomationRan, null, $payload);
    }

    /**
     * A rule-level cooldown stops the same rule from matching the same subject repeatedly.
     */
    protected function withinRuleCooldown(AutomationRule $rule, Conversation|Issue $subject): bool
    {
        if (! $rule->cooldown_minutes) {
            return false;
        }

        return AutomationRun::query()
            ->where('automation_rule_id', $rule->id)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('status', 'matched')
            ->where('created_at', '>=', now()->subMinutes($rule->cooldown_minutes))
            ->exists();
    }

    /**
     * Whether a rule already ran for this subject and trigger today (scheduled triggers).
     */
    public function ranToday(AutomationRule $rule, Conversation|Issue $subject, AutomationTrigger $trigger, string $timezone = 'UTC'): bool
    {
        return AutomationRun::query()
            ->where('automation_rule_id', $rule->id)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('trigger', $trigger->value)
            ->where('created_at', '>=', now($timezone)->startOfDay()->utc())
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function record(AutomationRule $rule, Conversation|Issue $subject, AutomationTrigger $trigger, string $status, array $details): AutomationRun
    {
        return AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'conversation_id' => $subject instanceof Conversation ? $subject->id : null,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'trigger' => $trigger->value,
            'status' => $status,
            'details' => $details,
        ]);
    }
}
