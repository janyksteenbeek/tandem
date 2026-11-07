<?php

namespace Database\Factories\Projects;

use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;
use App\Models\Projects\WorkflowStatus;
use App\Models\Team;
use App\Services\Projects\WorkflowActions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueWorkstream>
 */
class IssueWorkstreamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'team_id' => function (array $attributes) {
                $issue = Issue::query()->whereKey($attributes['issue_id'])->firstOrFail();

                return Team::factory()->create(['organization_id' => $issue->organization_id])->id;
            },
            'status_id' => function (array $attributes) {
                $team = Team::query()->whereKey($attributes['team_id'])->firstOrFail();

                return app(WorkflowActions::class)->forTeam($team)->defaultStatus()->id;
            },
            'status_category' => fn (array $attributes) => WorkflowStatus::query()->whereKey($attributes['status_id'])->firstOrFail()->category,
            'is_required' => true,
            'position' => 0,
        ];
    }
}
