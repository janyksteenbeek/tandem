<?php

use App\Enums\Projects\StatusCategory;
use App\Models\Projects\Product;
use App\Services\Projects\IssueActions;
use App\Services\Projects\ProductActions;
use App\Services\Projects\ProjectActions;
use App\Services\Projects\ProjectProgress;
use App\Services\Projects\WorkflowActions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectsTestHelpers;

beforeEach(function () {
    Notification::fake();
    $this->withoutVite();

    $w = ProjectsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->member = $w['member'];
    $this->other = $w['other'];
    $this->outsider = $w['outsider'];
    $this->engineering = $w['engineering'];
    $this->url = fn (string $name, array $parameters = []) => route($name, ['current_organization' => $this->organization->slug, ...$parameters]);
});

test('admins create products with a unique key and modules can nest', function () {
    $this->actingAs($this->member)->postJson(($this->url)('projects.products.store'), ['name' => 'Frontier', 'key' => 'FRONT'])->assertForbidden();

    $this->actingAs($this->admin)->postJson(($this->url)('projects.products.store'), ['name' => 'Frontier', 'key' => 'front', 'team_ids' => [$this->engineering->id]])
        ->assertCreated()
        ->assertJsonPath('product.key', 'FRONT')
        ->assertJsonPath('product.teams.0.id', $this->engineering->id);

    $this->actingAs($this->admin)->postJson(($this->url)('projects.products.store'), ['name' => 'Dup', 'key' => 'FRONT'])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson(($this->url)('projects.products.store'), ['name' => 'Bad', 'key' => '1AB'])->assertUnprocessable();

    $product = Product::query()->where('key', 'FRONT')->firstOrFail();

    $parent = app(ProductActions::class)->createModule($product, ['name' => 'Integrations'], $this->admin);
    $child = app(ProductActions::class)->createModule($product, ['name' => 'Gmail', 'parent_id' => $parent->id], $this->admin);

    expect($child->parent_id)->toBe($parent->id)
        ->and(fn () => app(ProductActions::class)->updateModule($parent, ['parent_id' => $child->id], $this->admin))->toThrow(ValidationException::class);

    $this->actingAs($this->member)
        ->get(($this->url)('projects.products.show', ['product' => $product->slug, 'tab' => 'modules']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('projects/products/Show')->where('tab', 'modules')->has('product.modules', 2));
});

test('projects derive progress from issue completion and ignore canceled work', function () {
    $product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $project = app(ProjectActions::class)->create($this->organization, ['name' => 'Reporting Redesign', 'product_id' => $product->id], $this->admin);
    $actions = app(IssueActions::class);
    $workflow = app(WorkflowActions::class)->issueWorkflowFor($this->organization, $product);

    $issues = collect(range(1, 4))->map(fn ($i) => $actions->create($this->organization, ['title' => "Task $i", 'product' => $product, 'project' => $project], $this->admin));

    $actions->setStatus($issues[0], $workflow->statusFor(StatusCategory::Completed), $this->admin);
    $actions->setStatus($issues[1], $workflow->statusFor(StatusCategory::Completed), $this->admin);
    $actions->setStatus($issues[2], $workflow->statusFor(StatusCategory::Canceled), $this->admin);

    $progress = app(ProjectProgress::class)->recompute($project);

    expect($progress['total'])->toBe(4)
        ->and($progress['completed'])->toBe(2)
        ->and($progress['canceled'])->toBe(1)
        ->and($progress['percent'])->toEqual(66.7)
        ->and($project->fresh()->completed_issues_count)->toBe(2);
});

test('private projects are invisible to non-participants and visible to their teams', function () {
    $project = app(ProjectActions::class)->create($this->organization, ['name' => 'Secret', 'visibility' => 'private', 'team_ids' => [$this->engineering->id]], $this->admin);

    $this->actingAs($this->other)->get(($this->url)('projects.projects.show', ['project' => $project->slug]))->assertForbidden();
    $this->actingAs($this->member)->get(($this->url)('projects.projects.show', ['project' => $project->slug]))->assertOk();
    $this->actingAs($this->outsider)->get(($this->url)('projects.projects.show', ['project' => $project->slug]))->assertForbidden();

    $this->actingAs($this->other)
        ->get(($this->url)('projects.projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('projects', 0));
});

test('product and team pages scope issues to the entity', function () {
    $product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $elsewhere = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'OTHER']);
    $actions = app(IssueActions::class);

    $actions->create($this->organization, ['title' => 'In product', 'product' => $product], $this->admin, [['team' => $this->engineering]]);
    $actions->create($this->organization, ['title' => 'Elsewhere', 'product' => $elsewhere], $this->admin);

    $this->actingAs($this->admin)
        ->get(($this->url)('projects.products.show', ['product' => $product->slug, 'tab' => 'issues']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('issues.data', 1));

    $this->actingAs($this->admin)
        ->get(($this->url)('projects.teams.show', ['team' => $this->engineering->slug, 'tab' => 'issues']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('issues.data', 1, fn (Assert $row) => $row->where('identifier', 'FRONT-1')->has('workstream')->etc())
            ->where('workflow.kind', 'team'));
});

test('organization project settings validate the issue key against product keys', function () {
    Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);

    $this->actingAs($this->admin)->patch(($this->url)('projects.settings.update'), ['issue_key' => 'front'])->assertSessionHasErrors('issue_key');
    $this->actingAs($this->admin)->patch(($this->url)('projects.settings.update'), ['issue_key' => 'NWL', 'auto_complete' => false])->assertRedirect();

    expect($this->organization->fresh()->issue_key)->toBe('NWL')
        ->and(app(WorkflowActions::class)->defaultIssueWorkflow($this->organization)->auto_complete)->toBeFalse();

    $this->actingAs($this->member)->patch(($this->url)('projects.settings.update'), ['issue_key' => 'NWL2'])->assertForbidden();
});
