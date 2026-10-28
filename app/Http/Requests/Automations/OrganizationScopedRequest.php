<?php

namespace App\Http\Requests\Automations;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for requests under the `{current_organization}` prefix. Membership and role checks are
 * handled by the EnsureOrganizationMembership middleware; this only resolves the organization.
 */
abstract class OrganizationScopedRequest extends FormRequest
{
    protected ?Organization $resolvedOrganization = null;

    public function organization(): Organization
    {
        if ($this->resolvedOrganization) {
            return $this->resolvedOrganization;
        }

        $parameter = $this->route('current_organization');

        $organization = $parameter instanceof Organization
            ? $parameter
            : Organization::query()->where('slug', (string) $parameter)->firstOrFail();

        return $this->resolvedOrganization = $organization;
    }
}
