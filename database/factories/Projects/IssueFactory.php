<?php

namespace Database\Factories\Projects;

use App\Enums\Projects\IssuePriority;
use App\Enums\Projects\StatusCategory;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\WorkflowStatus;
use App\Services\Projects\WorkflowActions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates issues directly, bypassing IssueActions. Identifiers come from a per-process counter so
 * factories stay transaction-free; use IssueActions::create in behaviour tests that care about
 * numbering.
 *
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    protected static int $counter = 0;

    public function definition(): array
    {
        $number = ++static::$counter;

        return [
            'organization_id' => Organization::factory(),
            'prefix' => 'TST',
            'number' => $number,
            'identifier' => 'TST-'.$number,
            'title' => rtrim(fake()->sentence(fake()->numberBetween(3, 8)), '.'),
            'description_html' => '<p>'.fake()->paragraph().'</p>',
            'description_text' => fn (array $attributes) => strip_tags((string) $attributes['description_html']),
            'status_id' => function (array $attributes) {
                $organization = Organization::query()->whereKey($attributes['organization_id'])->firstOrFail();

                return app(WorkflowActions::class)->defaultIssueWorkflow($organization)->defaultStatus()->id;
            },
            'status_category' => fn (array $attributes) => WorkflowStatus::query()->whereKey($attributes['status_id'])->firstOrFail()->category,
            'priority' => IssuePriority::None,
        ];
    }

    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $organization = Organization::query()->whereKey($attributes['organization_id'])->firstOrFail();
            $status = app(WorkflowActions::class)->defaultIssueWorkflow($organization)->statusFor(StatusCategory::Completed);

            return ['status_id' => $status?->id, 'status_category' => StatusCategory::Completed, 'completed_at' => now()];
        });
    }

    public function started(): static
    {
        return $this->state(function (array $attributes) {
            $organization = Organization::query()->whereKey($attributes['organization_id'])->firstOrFail();
            $status = app(WorkflowActions::class)->defaultIssueWorkflow($organization)->statusFor(StatusCategory::Started);

            return ['status_id' => $status?->id, 'status_category' => StatusCategory::Started, 'started_at' => now()];
        });
    }
}
