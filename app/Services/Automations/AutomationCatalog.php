<?php

namespace App\Services\Automations;

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionField;
use App\Enums\AutomationConditionOperator;
use App\Enums\AutomationTrigger;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;

/**
 * Everything the rule builder UI needs to render triggers, conditions, actions and variables.
 */
class AutomationCatalog
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'triggers' => AutomationTrigger::options(),
            'match' => [
                ['value' => 'all', 'label' => 'All conditions match'],
                ['value' => 'any', 'label' => 'Any condition matches'],
            ],
            'fields' => AutomationConditionField::options(),
            'operators' => AutomationConditionOperator::options(),
            'actions' => AutomationActionType::options(),
            'statuses' => collect(ConversationStatus::options())
                ->reject(fn (array $option) => $option['value'] === ConversationStatus::Snoozed->value)
                ->values()
                ->all(),
            'priorities' => ConversationPriority::options(),
            'strategies' => [
                ['value' => TeamAssigner::ROUND_ROBIN, 'label' => 'Round robin'],
                ['value' => TeamAssigner::LEAST_BUSY, 'label' => 'Least busy'],
            ],
            'variables' => TemplateRenderer::catalog(),
            'default_cooldown_minutes' => Autoresponder::DEFAULT_COOLDOWN_MINUTES,
        ];
    }
}
