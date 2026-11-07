<?php

namespace App\Services\Projects\Access;

use App\Enums\OrganizationRole;
use App\Enums\Projects\ProductMemberRole;
use App\Enums\Projects\ProjectAccessLevel;
use App\Enums\Projects\ProjectVisibility;
use App\Enums\TeamRole;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;
use App\Models\Projects\Product;
use App\Models\Projects\ProductMember;
use App\Models\Projects\Project;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single place that decides what a user may do with products, projects, issues and
 * workstreams. Policies compare the returned level; list queries use applyVisibility, which
 * mirrors levelFor() in SQL. Memoised per request (scoped binding, so Octane cannot leak it).
 */
class IssueAccess
{
    /** @var array<string, mixed> */
    protected array $memo = [];

    public function flush(): void
    {
        $this->memo = [];
    }

    public function isOrganizationAdmin(User $user, int $organizationId): bool
    {
        return $this->remember("admin:$user->id:$organizationId", function () use ($user, $organizationId) {
            $organization = Organization::query()->find($organizationId);

            return $organization !== null && ($user->organizationRole($organization)?->isAtLeast(OrganizationRole::Admin) ?? false);
        });
    }

    public function isOrganizationMember(User $user, int $organizationId): bool
    {
        return $this->remember("member:$user->id:$organizationId", function () use ($user, $organizationId) {
            $organization = Organization::query()->find($organizationId);

            return $organization !== null && $user->belongsToOrganization($organization);
        });
    }

    /**
     * Ids of the teams the user belongs to within an organization.
     *
     * @return array<int, int>
     */
    public function teamIds(User $user, int $organizationId): array
    {
        return $this->remember("teams:$user->id:$organizationId", fn () => TeamMember::query()
            ->join('teams', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.user_id', $user->id)
            ->where('teams.organization_id', $organizationId)
            ->whereNull('teams.deleted_at')
            ->pluck('teams.id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    /**
     * Teams the user leads within an organization.
     *
     * @return array<int, int>
     */
    public function ledTeamIds(User $user, int $organizationId): array
    {
        return $this->remember("led:$user->id:$organizationId", fn () => TeamMember::query()
            ->join('teams', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.user_id', $user->id)
            ->where('team_members.role', TeamRole::Lead->value)
            ->where('teams.organization_id', $organizationId)
            ->whereNull('teams.deleted_at')
            ->pluck('teams.id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    /**
     * Projects the user may see: every organization-visible one plus the private ones they
     * lead, are a member of, participate in through a team, or own through the product.
     *
     * @return array<int, int>
     */
    public function visibleProjectIds(User $user, int $organizationId): array
    {
        return $this->remember("projects:$user->id:$organizationId", function () use ($user, $organizationId) {
            $query = Project::query()->where('organization_id', $organizationId);

            if (! $this->isOrganizationAdmin($user, $organizationId)) {
                $teamIds = $this->teamIds($user, $organizationId);

                $query->where(function (Builder $q) use ($user, $teamIds) {
                    $q->where('visibility', ProjectVisibility::Organization->value)
                        ->orWhere('lead_id', $user->id)
                        ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->id))
                        ->orWhereHas('product', fn (Builder $p) => $p->where('owner_id', $user->id));

                    if ($teamIds !== []) {
                        $q->orWhereHas('teams', fn (Builder $t) => $t->whereIn('teams.id', $teamIds));
                    }
                });
            }

            return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
        });
    }

    public function productLevelFor(User $user, Product $product): ?ProjectAccessLevel
    {
        if (! $this->isOrganizationMember($user, $product->organization_id)) {
            return null;
        }

        if ($this->isOrganizationAdmin($user, $product->organization_id)) {
            return ProjectAccessLevel::Admin;
        }

        if ($product->owner_id === $user->id) {
            return ProjectAccessLevel::Manager;
        }

        $membership = ProductMember::query()->where('product_id', $product->id)->where('user_id', $user->id)->first();

        if ($membership) {
            return $membership->role === ProductMemberRole::Admin ? ProjectAccessLevel::Manager : ProjectAccessLevel::Participant;
        }

        if ($product->teams()->whereIn('teams.id', $this->teamIds($user, $product->organization_id))->exists()) {
            return ProjectAccessLevel::Participant;
        }

        return ProjectAccessLevel::Viewer;
    }

    public function projectLevelFor(User $user, Project $project): ?ProjectAccessLevel
    {
        if (! $this->isOrganizationMember($user, $project->organization_id)) {
            return null;
        }

        if ($this->isOrganizationAdmin($user, $project->organization_id)) {
            return ProjectAccessLevel::Admin;
        }

        if ($project->lead_id === $user->id) {
            return ProjectAccessLevel::Manager;
        }

        $project->loadMissing('product');

        if ($project->product && $project->product->owner_id === $user->id) {
            return ProjectAccessLevel::Manager;
        }

        if ($project->members()->whereKey($user->id)->exists()) {
            return ProjectAccessLevel::Participant;
        }

        if ($project->teams()->whereIn('teams.id', $this->teamIds($user, $project->organization_id))->exists()) {
            return ProjectAccessLevel::Participant;
        }

        return $project->isPrivate() ? null : ProjectAccessLevel::Viewer;
    }

    public function levelFor(User $user, Issue $issue): ?ProjectAccessLevel
    {
        if (! $this->isOrganizationMember($user, $issue->organization_id)) {
            return null;
        }

        if ($this->isOrganizationAdmin($user, $issue->organization_id)) {
            return ProjectAccessLevel::Admin;
        }

        $issue->loadMissing(['product', 'workstreams']);

        $teamIds = $this->teamIds($user, $issue->organization_id);
        $ledTeamIds = $this->ledTeamIds($user, $issue->organization_id);

        if ($issue->owner_id === $user->id) {
            return ProjectAccessLevel::Manager;
        }

        if ($issue->lead_team_id && in_array($issue->lead_team_id, $ledTeamIds, true)) {
            return ProjectAccessLevel::Manager;
        }

        if ($issue->product && $issue->product->owner_id === $user->id) {
            return ProjectAccessLevel::Manager;
        }

        if ($issue->product && $issue->product->members()->whereKey($user->id)->wherePivot('role', ProductMemberRole::Admin->value)->exists()) {
            return ProjectAccessLevel::Manager;
        }

        if ($issue->project_id) {
            $issue->loadMissing('project');

            if ($issue->project && $issue->project->lead_id === $user->id) {
                return ProjectAccessLevel::Manager;
            }
        }

        $participates = ($issue->lead_team_id && in_array($issue->lead_team_id, $teamIds, true))
            || $issue->workstreams->contains(fn (IssueWorkstream $w) => in_array($w->team_id, $teamIds, true))
            || $issue->created_by_id === $user->id
            || $this->isAssignee($user, $issue)
            || $issue->followers()->whereKey($user->id)->exists();

        if ($participates) {
            return ProjectAccessLevel::Participant;
        }

        if ($issue->project_id === null || in_array($issue->project_id, $this->visibleProjectIds($user, $issue->organization_id), true)) {
            return ProjectAccessLevel::Viewer;
        }

        return null;
    }

    /**
     * Working on a workstream only requires being on its team (or assigned to it).
     */
    public function workstreamLevelFor(User $user, IssueWorkstream $workstream): ?ProjectAccessLevel
    {
        $workstream->loadMissing('issue');
        $issueLevel = $this->levelFor($user, $workstream->issue);

        if ($issueLevel?->isAtLeast(ProjectAccessLevel::Manager)) {
            return $issueLevel;
        }

        if (in_array($workstream->team_id, $this->teamIds($user, $workstream->issue->organization_id), true)) {
            return ProjectAccessLevel::Participant;
        }

        if ($workstream->assignees()->whereKey($user->id)->exists()) {
            return ProjectAccessLevel::Participant;
        }

        // Being on another team of the issue lets you look, not touch.
        return $issueLevel === null ? null : ProjectAccessLevel::Viewer;
    }

    public function isTeamLead(User $user, Team $team): bool
    {
        return in_array($team->id, $this->ledTeamIds($user, $team->organization_id), true);
    }

    /**
     * SQL mirror of levelFor() for list queries.
     *
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    public function applyVisibility(Builder $query, User $user): Builder
    {
        $organizationId = $user->current_organization_id;

        if ($organizationId === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('issues.organization_id', $organizationId);

        if ($this->isOrganizationAdmin($user, $organizationId)) {
            return $query;
        }

        if (! $this->isOrganizationMember($user, $organizationId)) {
            return $query->whereRaw('1 = 0');
        }

        $teamIds = $this->teamIds($user, $organizationId);
        $projectIds = $this->visibleProjectIds($user, $organizationId);

        return $query->where(function (Builder $q) use ($user, $teamIds, $projectIds) {
            $q->where(function (Builder $visible) use ($projectIds) {
                $visible->whereNull('issues.project_id');

                if ($projectIds !== []) {
                    $visible->orWhereIn('issues.project_id', $projectIds);
                }
            })
                ->orWhere('issues.owner_id', $user->id)
                ->orWhere('issues.created_by_id', $user->id)
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('issue_followers')
                    ->whereColumn('issue_followers.issue_id', 'issues.id')
                    ->where('issue_followers.user_id', $user->id))
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('issue_workstream_assignees')
                    ->join('issue_workstreams', 'issue_workstreams.id', '=', 'issue_workstream_assignees.workstream_id')
                    ->whereColumn('issue_workstreams.issue_id', 'issues.id')
                    ->where('issue_workstream_assignees.user_id', $user->id));

            if ($teamIds !== []) {
                $q->orWhereIn('issues.lead_team_id', $teamIds)
                    ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                        ->from('issue_workstreams')
                        ->whereColumn('issue_workstreams.issue_id', 'issues.id')
                        ->whereIn('issue_workstreams.team_id', $teamIds));
            }
        });
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function applyProjectVisibility(Builder $query, User $user, int $organizationId): Builder
    {
        $query->where('projects.organization_id', $organizationId);

        if ($this->isOrganizationAdmin($user, $organizationId)) {
            return $query;
        }

        return $query->whereIn('projects.id', $this->visibleProjectIds($user, $organizationId));
    }

    protected function isAssignee(User $user, Issue $issue): bool
    {
        return DB::table('issue_workstream_assignees')
            ->join('issue_workstreams', 'issue_workstreams.id', '=', 'issue_workstream_assignees.workstream_id')
            ->where('issue_workstreams.issue_id', $issue->id)
            ->where('issue_workstream_assignees.user_id', $user->id)
            ->exists();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $resolver
     * @return T
     */
    protected function remember(string $key, callable $resolver): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $resolver();
        }

        return $this->memo[$key];
    }
}
