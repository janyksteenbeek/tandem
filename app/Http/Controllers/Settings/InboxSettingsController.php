<?php

namespace App\Http\Controllers\Settings;

use App\Enums\InboxRole;
use App\Enums\InboxType;
use App\Http\Controllers\Controller;
use App\Models\Inbox;
use App\Models\InboxTeam;
use App\Models\InboxUser;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InboxSettingsController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function index(Request $request, Organization $organization): Response
    {
        $this->authorize('view', $organization);

        $user = $request->user();

        $inboxes = $organization->inboxes()
            ->where('type', InboxType::Shared->value)
            ->with(['teams', 'users', 'mailAccounts'])
            ->get()
            ->filter(fn (Inbox $inbox) => $user->can('view', $inbox))
            ->map(fn (Inbox $inbox) => $this->payload($inbox, $user))
            ->values();

        return Inertia::render('settings/inboxes/Index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'inboxes' => $inboxes,
            'teams' => $organization->teams->map(fn (Team $team) => ['id' => $team->id, 'name' => $team->name, 'slug' => $team->slug, 'color' => $team->color])->values(),
            'members' => $organization->members()->orderBy('name')->get()->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email, 'initials' => $member->initials(), 'avatar' => $member->avatarUrl()])->values(),
            'roles' => InboxRole::options(),
            'canManage' => $user->can('manage', $organization),
            'forwardingDomain' => config('tandem.forwarding.domain'),
        ]);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email_address' => ['nullable', 'email', 'max:190'],
            'color' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:255'],
            'team_ids' => ['array'],
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')->where('organization_id', $organization->id)],
        ]);

        $inbox = $organization->inboxes()->create([
            'type' => InboxType::Shared,
            'name' => $data['name'],
            'email_address' => $data['email_address'] ?? null,
            'color' => $data['color'] ?? 'violet',
            'description' => $data['description'] ?? null,
            'position' => ($organization->inboxes()->max('position') ?? 0) + 1,
        ]);

        foreach ($data['team_ids'] ?? [] as $teamId) {
            $inbox->teams()->attach($teamId, ['role' => InboxRole::Agent->value]);
        }

        $inbox->users()->syncWithoutDetaching([$request->user()->id => ['role' => InboxRole::Admin->value]]);
        $this->audit->record('inbox.created', $organization, $request->user(), $inbox, ['name' => $inbox->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inbox ":name" created. Connect a channel to start receiving mail.', ['name' => $inbox->name])]);

        return to_route('settings.inboxes.index', $organization);
    }

    public function update(Request $request, Organization $organization, Inbox $inbox): RedirectResponse
    {
        $this->authorize('manage', $inbox);
        abort_unless($inbox->organization_id === $organization->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email_address' => ['nullable', 'email', 'max:190'],
            'color' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:255'],
            'settings' => ['nullable', 'array'],
        ]);

        $inbox->update($data);
        $this->audit->record('inbox.updated', $organization, $request->user(), $inbox, ['name' => $inbox->name, 'email_address' => $inbox->email_address, 'color' => $inbox->color]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inbox updated.')]);

        return back();
    }

    /**
     * Replace the full access list (teams + direct users) of an inbox.
     */
    public function access(Request $request, Organization $organization, Inbox $inbox): RedirectResponse
    {
        $this->authorize('manage', $inbox);
        abort_unless($inbox->organization_id === $organization->id, 404);

        $data = $request->validate([
            'teams' => ['array'],
            'teams.*.id' => ['required', 'integer', Rule::exists('teams', 'id')->where('organization_id', $organization->id)],
            'teams.*.role' => ['required', Rule::enum(InboxRole::class)],
            'users' => ['array'],
            'users.*.id' => ['required', 'integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $organization->id)],
            'users.*.role' => ['required', Rule::enum(InboxRole::class)],
        ]);

        $teams = [];
        $users = [];

        foreach ($data['teams'] ?? [] as $grant) {
            $teams[(int) $grant['id']] = ['role' => $grant['role']];
        }

        foreach ($data['users'] ?? [] as $grant) {
            $users[(int) $grant['id']] = ['role' => $grant['role']];
        }

        $inbox->teams()->sync($teams);
        $inbox->users()->sync($users);
        $this->audit->record('inbox.access_updated', $organization, $request->user(), $inbox, ['teams' => $teams, 'users' => $users]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access updated.')]);

        return back();
    }

    public function destroy(Request $request, Organization $organization, Inbox $inbox): RedirectResponse
    {
        $this->authorize('manage', $inbox);
        abort_unless($inbox->organization_id === $organization->id, 404);

        $inbox->delete();
        $this->audit->record('inbox.deleted', $organization, $request->user(), $inbox, ['name' => $inbox->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inbox deleted.')]);

        return to_route('settings.inboxes.index', $organization);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Inbox $inbox, User $user): array
    {
        return [
            'uuid' => $inbox->uuid,
            'name' => $inbox->name,
            'slug' => $inbox->slug,
            'color' => $inbox->color,
            'description' => $inbox->description,
            'email_address' => $inbox->email_address,
            'can_manage' => $user->can('manage', $inbox),
            'teams' => InboxTeam::query()->where('inbox_id', $inbox->id)->with('team')->get()->map(fn (InboxTeam $grant) => ['id' => $grant->team->id, 'name' => $grant->team->name, 'color' => $grant->team->color, 'role' => $grant->role->value])->values(),
            'users' => InboxUser::query()->where('inbox_id', $inbox->id)->get()->map(function (InboxUser $grant) use ($inbox) {
                $member = $inbox->users->firstWhere('id', $grant->user_id);

                return $member ? ['id' => $member->id, 'name' => $member->name, 'email' => $member->email, 'initials' => $member->initials(), 'avatar' => $member->avatarUrl(), 'role' => $grant->role->value] : null;
            })->filter()->values(),
            'accounts' => $inbox->mailAccounts->map(fn ($account) => [
                'uuid' => $account->uuid,
                'provider' => $account->provider->value,
                'provider_label' => $account->provider->label(),
                'email' => $account->email_address,
                'display_name' => $account->display_name,
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'healthy' => $account->status->isHealthy(),
                'last_synced_at' => $account->last_synced_at?->toISOString(),
                'last_error' => $account->last_error,
                'received_count' => $account->syncState('received_count', 0),
            ])->values(),
            'conversation_count' => $inbox->conversations()->count(),
        ];
    }
}
