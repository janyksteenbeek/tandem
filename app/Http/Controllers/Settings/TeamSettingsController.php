<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamSettingsController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function index(Request $request, Organization $organization): Response
    {
        $this->authorize('view', $organization);

        return Inertia::render('settings/teams/Index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'teams' => $organization->teams()->with(['members', 'inboxes'])->get()->map(function (Team $team) {
                $roles = TeamMember::query()->where('team_id', $team->id)->pluck('role', 'user_id');

                return [
                    'id' => $team->id,
                    'slug' => $team->slug,
                    'name' => $team->name,
                    'color' => $team->color,
                    'description' => $team->description,
                    'members' => $team->members->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email, 'initials' => $member->initials(), 'avatar' => $member->avatarUrl(), 'role' => $roles[$member->id] ?? TeamRole::Member->value])->values(),
                    'inboxes' => $team->inboxes->map(fn ($inbox) => ['uuid' => $inbox->uuid, 'name' => $inbox->name, 'color' => $inbox->color])->values(),
                ];
            })->values(),
            'members' => $organization->members()->orderBy('name')->get()->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email, 'initials' => $member->initials(), 'avatar' => $member->avatarUrl()])->values(),
            'canManage' => $request->user()->can('manage', $organization),
        ]);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('manage', $organization);

        $data = $this->validated($request, $organization);

        $team = $organization->teams()->create(collect($data)->only(['name', 'color', 'description'])->all());
        $this->syncMembers($team, $data['members'] ?? []);
        $this->audit->record('team.created', $organization, $request->user(), $team, ['name' => $team->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team ":name" created.', ['name' => $team->name])]);

        return back();
    }

    public function update(Request $request, Organization $organization, Team $team): RedirectResponse
    {
        $this->authorize('manage', $team);
        abort_unless($team->organization_id === $organization->id, 404);

        $data = $this->validated($request, $organization);

        $team->update(collect($data)->only(['name', 'color', 'description'])->all());

        if (array_key_exists('members', $data)) {
            $this->syncMembers($team, $data['members']);
        }

        $this->audit->record('team.updated', $organization, $request->user(), $team, collect($data)->only(['name', 'color', 'description'])->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

        return back();
    }

    public function destroy(Request $request, Organization $organization, Team $team): RedirectResponse
    {
        $this->authorize('manage', $team);
        abort_unless($team->organization_id === $organization->id, 404);

        $team->delete();
        $this->audit->record('team.deleted', $organization, $request->user(), $team, ['name' => $team->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team deleted.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, Organization $organization): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:255'],
            'members' => ['sometimes', 'array'],
            'members.*.id' => ['required', 'integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $organization->id)],
            'members.*.role' => ['nullable', Rule::enum(TeamRole::class)],
        ]);
    }

    /**
     * @param  array<int, array{id: int, role?: string|null}>  $members
     */
    protected function syncMembers(Team $team, array $members): void
    {
        $team->members()->sync(collect($members)->mapWithKeys(fn (array $m) => [$m['id'] => ['role' => $m['role'] ?? TeamRole::Member->value]])->all());
    }
}
