<?php

use App\Models\Mail\Inbox;
use App\Models\Projects\Product;
use App\Models\Team;
use App\Services\Projects\ProjectsNavigation;
use App\Services\Projects\WorkflowActions;
use Tests\Support\ProjectsTestHelpers;

beforeEach(function () {
    $w = ProjectsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->member = $w['member'];
    $this->engineering = $w['engineering'];
    $this->design = $w['design'];
    $this->navigation = app(ProjectsNavigation::class);
});

test('mail-only teams stay out of the projects navigation', function () {
    $support = Team::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Support']);
    $support->members()->attach($this->member->id, ['role' => 'member']);
    $inbox = Inbox::factory()->shared()->create(['organization_id' => $this->organization->id]);
    $inbox->teams()->attach($support->id);

    app(WorkflowActions::class)->forTeam($this->engineering);
    $product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $product->teams()->attach($this->design->id);

    $nav = $this->navigation->for($this->member, $this->organization);
    $teams = collect($nav['teams'])->keyBy('name');

    expect($teams->keys()->all())->toEqualCanonicalizing(['Engineering', 'Design'])
        ->and($teams['Engineering']['is_member'])->toBeTrue()
        ->and($teams['Design']['is_member'])->toBeFalse();
});
