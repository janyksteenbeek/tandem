<?php

use App\Enums\Projects\StatusCategory;
use App\Models\Projects\Product;
use App\Services\Projects\IssueActions;
use App\Services\Projects\WorkflowActions;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectsTestHelpers;

beforeEach(function () {
    Notification::fake();
    $this->withoutVite();

    $w = ProjectsTestHelpers::workspace();
    $this->organization = $w['organization'];
    $this->admin = $w['admin'];
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT']);
    $this->other = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'OTHER']);
    $this->actions = app(IssueActions::class);
});

test('product issue counters follow creation, completion, moves and deletion', function () {
    $first = $this->actions->create($this->organization, ['title' => 'One', 'product' => $this->product], $this->admin);
    $second = $this->actions->create($this->organization, ['title' => 'Two', 'product' => $this->product], $this->admin);

    expect($this->product->refresh())->issues_count->toBe(2)->open_issues_count->toBe(2);

    $done = app(WorkflowActions::class)->issueWorkflowFor($this->organization, $this->product)->statusFor(StatusCategory::Completed);
    $this->actions->setStatus($first, $done, $this->admin);

    expect($this->product->refresh()->open_issues_count)->toBe(1);

    $this->actions->move($second, $this->other, null, $this->admin);

    expect($this->product->refresh())->issues_count->toBe(1)->open_issues_count->toBe(0)
        ->and($this->other->refresh()->open_issues_count)->toBe(1);

    $this->actions->delete($first, $this->admin);

    expect($this->product->refresh()->issues_count)->toBe(0);
});

test('the products index recounts before rendering', function () {
    $this->actions->create($this->organization, ['title' => 'One', 'product' => $this->product], $this->admin);
    $this->product->forceFill(['open_issues_count' => 0])->saveQuietly();

    $this->actingAs($this->admin)
        ->get(route('projects.products.index', ['current_organization' => $this->organization->slug]))
        ->assertInertia(fn (Assert $page) => $page->component('projects/products/Index')
            ->where('products', fn ($products) => collect($products)->firstWhere('key', 'FRONT')['open_issues_count'] === 1));
});
