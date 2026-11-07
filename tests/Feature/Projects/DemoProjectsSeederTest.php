<?php

use App\Enums\OrganizationRole;
use App\Enums\Projects\StatusCategory;
use App\Models\Automations\AutomationRule;
use App\Models\Organization;
use App\Models\Projects\Cycle;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueConversationLink;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Projects\WorkstreamDependency;
use App\Models\User;
use Database\Seeders\DemoProjectsSeeder;
use Database\Seeders\DemoSeeder;

test('the demo seeder builds a coherent cross-team workspace', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoProjectsSeeder::class);

    $organization = Organization::query()->where('slug', 'northwind-labs')->firstOrFail();
    $front = Product::query()->where('key', 'FRONT')->firstOrFail();
    $showcase = Issue::query()->where('identifier', 'FRONT-1')->firstOrFail();
    $overridden = Issue::query()->where('identifier', 'FRONT-5')->firstOrFail();
    $orgLevel = Issue::query()->where('identifier', 'NWL-1')->firstOrFail();

    expect($organization->issue_key)->toBe('NWL')
        ->and(Product::query()->count())->toBe(2)
        ->and(Project::query()->count())->toBe(3)
        ->and(Issue::query()->count())->toBeGreaterThanOrEqual(40)
        ->and($front->modules()->count())->toBe(7)
        ->and($showcase->workstreams()->count())->toBe(3)
        ->and($showcase->fresh()->status_category)->toBe(StatusCategory::Started)
        ->and($showcase->workstreams()->whereHas('team', fn ($q) => $q->where('name', 'QA'))->first()->status_category)->toBe(StatusCategory::Started)
        ->and(WorkstreamDependency::query()->whereNull('resolved_at')->count())->toBeGreaterThan(0)
        ->and(WorkstreamDependency::query()->whereNotNull('resolved_at')->count())->toBeGreaterThan(0)
        ->and($overridden->status_category)->toBe(StatusCategory::Completed)
        ->and($overridden->status_overridden_at)->not->toBeNull()
        ->and($orgLevel->product_id)->toBeNull()
        ->and(Issue::query()->whereNotNull('parent_id')->count())->toBe(3)
        ->and(Issue::query()->where('status_category', StatusCategory::Completed->value)->count())->toBeGreaterThanOrEqual(6)
        ->and(Issue::query()->where('blocked_by_count', '>', 0)->count())->toBeGreaterThan(0)
        ->and(IssueConversationLink::query()->count())->toBe(4)
        ->and(Cycle::query()->where('status', 'active')->count())->toBe(2)
        ->and(AutomationRule::query()->where('subject_type', 'issue')->count())->toBe(3)
        ->and(Project::query()->where('visibility', 'private')->first()->progress)->not->toBeNull();

    $newcomer = User::factory()->create();
    $organization->memberships()->create(['user_id' => $newcomer->id, 'role' => OrganizationRole::Member]);
    $newcomer->forceFill(['current_organization_id' => $organization->id])->save();
    $private = Project::query()->where('visibility', 'private')->firstOrFail();

    expect(Issue::query()->visibleTo($newcomer)->where('project_id', $private->id)->count())->toBe(0)
        ->and(Issue::query()->visibleTo($newcomer)->count())->toBeGreaterThan(20);
});
