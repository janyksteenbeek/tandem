<?php

namespace App\Services\Automations;

use App\Enums\AutomationTrigger;
use App\Enums\ConversationEventType;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the organization's automation rules for one trigger against one conversation. Each rule
 * is evaluated and executed in isolation: a broken rule is logged and audited but never stops
 * the next one, and nothing ever propagates back into the ingestion path.
 */
class AutomationEngine
{
    public function __construct(
        protected ConditionEvaluator $evaluator,
        protected ActionExecutor $executor,
        protected TriggerEchoGuard $echoes,
    ) {}

    /**
     * @param  array{message?: Message|null, source?: string}  $context
     */
    public function run(AutomationTrigger $trigger, Conversation $conversation, array $context = []): void
    {
        if ($conversation->organization_id === null) {
            return;
        }

        if (! $this->echoes->enter($conversation, $trigger)) {
            return;
        }

        try {
            $conversation->loadMissing(['inbox', 'organization']);

            foreach ($this->rulesFor($trigger, $conversation) as $rule) {
                $this->runRule($rule, $trigger, $conversation, $context);
            }
        } catch (Throwable $e) {
            Log::error('Automation engine failed', [
                'trigger' => $trigger->value,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->echoes->leave($conversation, $trigger);
        }
    }

    /**
     * Active rules of the conversation's organization for a trigger, scoped to its owning inbox.
     *
     * @return Collection<int, AutomationRule>
     */
    public function rulesFor(AutomationTrigger $trigger, Conversation $conversation): Collection
    {
        return AutomationRule::query()
            ->where('organization_id', $conversation->organization_id)
            ->where('trigger', $trigger->value)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('inbox_id')->orWhere('inbox_id', $conversation->inbox_id))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Evaluate a rule without executing anything. Used by the dry-run endpoint.
     *
     * @param  array<int, array{field?: mixed, operator?: mixed, value?: mixed}>  $conditions
     * @param  array{message?: Message|null}  $context
     * @return array{matched: bool, match: string, conditions: array<int, array<string, mixed>>}
     */
    public function dryRun(array $conditions, string $match, Conversation $conversation, array $context = []): array
    {
        $conversation->loadMissing(['inbox', 'organization']);

        return $this->evaluator->evaluate($conditions, $match, $conversation, $context);
    }

    /**
     * @param  array{message?: Message|null, source?: string}  $context
     */
    protected function runRule(AutomationRule $rule, AutomationTrigger $trigger, Conversation $conversation, array $context): void
    {
        $performed = [];

        try {
            $evaluation = $this->evaluator->evaluate($rule->conditions ?? [], $rule->match, $conversation, $context);

            if (! $evaluation['matched']) {
                $this->record($rule, $conversation, $trigger, 'skipped', ['reason' => 'conditions_not_met', 'conditions' => $evaluation['conditions'], 'match' => $evaluation['match']]);

                return;
            }

            if ($this->withinRuleCooldown($rule, $conversation)) {
                $this->record($rule, $conversation, $trigger, 'skipped', ['reason' => 'cooldown', 'conditions' => $evaluation['conditions'], 'match' => $evaluation['match']]);

                return;
            }

            DB::transaction(function () use ($rule, $trigger, $conversation, $context, $evaluation, &$performed) {
                foreach ($rule->actions ?? [] as $action) {
                    $performed[] = $this->executor->execute($rule, $action, $conversation, $context);
                }

                $rule->forceFill(['run_count' => $rule->run_count + 1, 'last_run_at' => now()])->save();

                $this->record($rule, $conversation, $trigger, 'matched', [
                    'conditions' => $evaluation['conditions'],
                    'match' => $evaluation['match'],
                    'actions' => $performed,
                ]);

                $conversation->events()->create([
                    'type' => ConversationEventType::AutomationRan,
                    'actor_id' => null,
                    'payload' => [
                        'rule_id' => $rule->id,
                        'rule_uuid' => $rule->uuid,
                        'rule_name' => $rule->name,
                        'trigger' => $trigger->value,
                        'actions' => $performed,
                    ],
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('Automation rule failed', [
                'rule_id' => $rule->id,
                'conversation_id' => $conversation->id,
                'trigger' => $trigger->value,
                'error' => $e->getMessage(),
            ]);

            try {
                $this->record($rule, $conversation, $trigger, 'failed', [
                    'error' => Str::limit($e->getMessage(), 1000),
                    'exception' => $e::class,
                    'actions' => $performed,
                ]);
            } catch (Throwable $inner) {
                Log::error('Automation audit write failed', ['rule_id' => $rule->id, 'error' => $inner->getMessage()]);
            }

            $conversation->refresh();
        }
    }

    /**
     * A rule-level cooldown stops the same rule from matching the same conversation repeatedly.
     */
    protected function withinRuleCooldown(AutomationRule $rule, Conversation $conversation): bool
    {
        if (! $rule->cooldown_minutes) {
            return false;
        }

        return AutomationRun::query()
            ->where('automation_rule_id', $rule->id)
            ->where('conversation_id', $conversation->id)
            ->where('status', 'matched')
            ->where('created_at', '>=', now()->subMinutes($rule->cooldown_minutes))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function record(AutomationRule $rule, Conversation $conversation, AutomationTrigger $trigger, string $status, array $details): AutomationRun
    {
        return AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'conversation_id' => $conversation->id,
            'trigger' => $trigger->value,
            'status' => $status,
            'details' => $details,
        ]);
    }
}
