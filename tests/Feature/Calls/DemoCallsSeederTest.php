<?php

use App\Models\Calls\Call;
use App\Models\Calls\CallRecording;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DemoCallsSeeder;
use Database\Seeders\DemoProjectsSeeder;
use Database\Seeders\DemoSeeder;

test('the demo seeder adds a meeting history the demo user can open, once', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoProjectsSeeder::class);
    $this->seed(DemoCallsSeeder::class);
    $this->seed(DemoCallsSeeder::class);

    $organization = Organization::query()->where('slug', 'northwind-labs')->firstOrFail();
    $demo = User::query()->where('email', 'demo@tandem.test')->firstOrFail();
    $review = Call::query()->where('status', 'ended')->whereHas('links')->firstOrFail();

    expect(Call::query()->where('organization_id', $organization->id)->count())->toBe(3)
        ->and(Call::query()->where('status', 'ended')->count())->toBe(2)
        ->and($review->message_count)->toBe(4)
        ->and($review->messages()->count())->toBe(4)
        ->and($demo->can('view', $review))->toBeTrue()
        ->and(CallRecording::count())->toBe(0);
});
