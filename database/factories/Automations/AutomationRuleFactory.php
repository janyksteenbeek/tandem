<?php

namespace Database\Factories\Automations;

use App\Enums\Automations\AutomationTrigger;
use App\Models\Automations\AutomationRule;
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

    public function issue(): static
    {
        return $this->state(fn () => ['subject_type' => 'issue', 'inbox_id' => null, 'trigger' => 'issue_created']);
    }
}
