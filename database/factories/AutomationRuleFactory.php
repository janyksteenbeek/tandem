<?php

namespace Database\Factories;

use App\Enums\AutomationTrigger;
use App\Models\AutomationRule;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->sentence(3),
            'trigger' => AutomationTrigger::ConversationReceived,
            'match' => 'all',
            'conditions' => [],
            'actions' => [['type' => 'add_tag', 'params' => ['tag' => 'auto']]],
            'is_active' => true,
        ];
    }
}
