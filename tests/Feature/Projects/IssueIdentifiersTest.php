<?php

use App\Models\Projects\Product;
use App\Services\Projects\IssueActions;
use App\Services\Projects\IssueIdentifiers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ProjectsTestHelpers;

beforeEach(function () {
    Notification::fake();

    $this->workspace = ProjectsTestHelpers::workspace();
    $this->organization = $this->workspace['organization'];
    $this->admin = $this->workspace['admin'];
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'FRONT', 'name' => 'Frontier']);
});

test('issues in a product are numbered sequentially with the product key', function () {
    $actions = app(IssueActions::class);

    $first = $actions->create($this->organization, ['title' => 'One', 'product' => $this->product], $this->admin);
    $second = $actions->create($this->organization, ['title' => 'Two', 'product' => $this->product], $this->admin);

    expect($first->identifier)->toBe('FRONT-1')
        ->and($second->identifier)->toBe('FRONT-2')
        ->and($second->prefix)->toBe('FRONT')
        ->and($second->number)->toBe(2);
});

test('organization level issues use the organization key or a derived one', function () {
    $actions = app(IssueActions::class);

    $derived = $actions->create($this->organization, ['title' => 'Office move'], $this->admin);
    expect($derived->identifier)->toBe('NL-1');

    $this->organization->forceFill(['issue_key' => 'NWL'])->save();
    $this->organization->refresh();

    $keyed = $actions->create($this->organization, ['title' => 'Second'], $this->admin);
    expect($keyed->identifier)->toBe('NWL-2');
});

test('the counter row is locked for update inside the creating transaction', function () {
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    app(IssueActions::class)->create($this->organization, ['title' => 'Locked', 'product' => $this->product], $this->admin);

    expect(collect($statements)->contains(fn (string $sql) => str_contains(strtolower($sql), 'issue_sequences') && str_contains(strtolower($sql), 'for update')))->toBeTrue();
});

test('a failed insert rolls the counter back', function () {
    $actions = app(IssueActions::class);

    try {
        DB::transaction(function () use ($actions) {
            $actions->create($this->organization, ['title' => 'Will roll back', 'product' => $this->product], $this->admin);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    $issue = $actions->create($this->organization, ['title' => 'After rollback', 'product' => $this->product], $this->admin);

    expect($issue->identifier)->toBe('FRONT-1');
});

test('moving an issue to another product keeps its identifier', function () {
    $actions = app(IssueActions::class);
    $other = Product::factory()->create(['organization_id' => $this->organization->id, 'key' => 'TAND']);

    $issue = $actions->create($this->organization, ['title' => 'Movable', 'product' => $this->product], $this->admin);
    $actions->move($issue, $other, null, $this->admin);

    expect($issue->fresh()->identifier)->toBe('FRONT-1')
        ->and($issue->fresh()->product_id)->toBe($other->id);

    $next = $actions->create($this->organization, ['title' => 'In TAND', 'product' => $other], $this->admin);
    expect($next->identifier)->toBe('TAND-1');
});

test('a product key may not collide with the organization key or another product', function () {
    $identifiers = app(IssueIdentifiers::class);

    expect($identifiers->isProductKeyAvailable($this->organization, 'FRONT'))->toBeFalse()
        ->and($identifiers->isProductKeyAvailable($this->organization, 'front', $this->product))->toBeTrue()
        ->and($identifiers->isProductKeyAvailable($this->organization, 'NL'))->toBeFalse()
        ->and($identifiers->isProductKeyAvailable($this->organization, 'NEW'))->toBeTrue()
        ->and($identifiers->isOrganizationKeyAvailable($this->organization, 'FRONT'))->toBeFalse();
});

test('derived keys are short, uppercase and start with a letter', function (string $name, string $expected) {
    expect(IssueIdentifiers::deriveKey($name))->toBe($expected);
})->with([
    ['Northwind Labs', 'NL'],
    ['Tandem', 'TAND'],
    ['Portivio Frontier Systems BV', 'PFSB'],
    ['42 Shops', 'X4S'],
]);
