<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookReceipt;

test('creating a shared inbox writes an audit entry', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create(['current_organization_id' => $organization->id]);
    $organization->memberships()->create(['user_id' => $admin->id, 'role' => OrganizationRole::Owner]);

    $this->actingAs($admin)
        ->post(route('settings.inboxes.store', $organization), ['name' => 'Support', 'email_address' => 'support@acme.test'])
        ->assertRedirect();

    $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'user_id' => $admin->id, 'action' => 'inbox.created']);
});

test('the prune command removes old operational rows', function () {
    WebhookReceipt::create(['provider' => 'cloudflare', 'external_id' => 'old', 'status' => 'processed', 'received_at' => now()->subDays(60), 'processed_at' => now()->subDays(60)]);
    WebhookReceipt::create(['provider' => 'cloudflare', 'external_id' => 'fresh', 'status' => 'processed', 'received_at' => now(), 'processed_at' => now()]);

    $this->artisan('tandem:prune')->assertSuccessful();

    expect(WebhookReceipt::count())->toBe(1);
});
