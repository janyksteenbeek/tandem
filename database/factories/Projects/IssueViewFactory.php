<?php

namespace Database\Factories\Projects;

use App\Enums\Projects\IssueViewScope;
use App\Models\Organization;
use App\Models\Projects\IssueView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueView>
 */
class IssueViewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'scope' => IssueViewScope::Personal,
            'user_id' => User::factory(),
            'name' => rtrim(fake()->unique()->sentence(2), '.'),
            'filters' => ['match' => 'all', 'groups' => []],
            'display' => ['mode' => 'list', 'group_by' => 'status'],
        ];
    }
}
