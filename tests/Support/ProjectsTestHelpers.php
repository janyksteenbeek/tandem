<?php

namespace Tests\Support;

use App\Enums\OrganizationRole;
use App\Enums\TeamRole;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;

/**
 * Builds the usual cast for project tests: an organization with an admin, two members and teams.
 */
class ProjectsTestHelpers
{
    /**
     * @return array{organization: Organization, admin: User, member: User, other: User, outsider: User, engineering: Team, design: Team}
     */
    public static function workspace(): array
    {
        $organization = Organization::factory()->create(['name' => 'Northwind Labs']);

        $admin = User::factory()->create(['current_organization_id' => $organization->id]);
        $member = User::factory()->create(['current_organization_id' => $organization->id]);
        $other = User::factory()->create(['current_organization_id' => $organization->id]);
        $outsider = User::factory()->create();

        $organization->memberships()->create(['user_id' => $admin->id, 'role' => OrganizationRole::Admin]);
        $organization->memberships()->create(['user_id' => $member->id, 'role' => OrganizationRole::Member]);
        $organization->memberships()->create(['user_id' => $other->id, 'role' => OrganizationRole::Member]);

        // The user factory switches every new user to their personal organization; switch back.
        foreach ([$admin, $member, $other] as $user) {
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        }

        $engineering = Team::factory()->create(['organization_id' => $organization->id, 'name' => 'Engineering']);
        $design = Team::factory()->create(['organization_id' => $organization->id, 'name' => 'Design']);

        $engineering->members()->attach($member->id, ['role' => TeamRole::Lead->value]);
        $design->members()->attach($other->id, ['role' => TeamRole::Member->value]);

        return compact('organization', 'admin', 'member', 'other', 'outsider', 'engineering', 'design');
    }
}
