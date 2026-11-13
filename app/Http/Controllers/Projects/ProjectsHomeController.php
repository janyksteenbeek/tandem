<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;

class ProjectsHomeController extends Controller
{
    public function __invoke(Organization $currentOrganization): RedirectResponse
    {
        return to_route('projects.my-issues', ['current_organization' => $currentOrganization->slug]);
    }
}
