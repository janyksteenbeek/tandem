<?php

namespace App\Services\Projects;

use App\Enums\Projects\IssueActivityType;
use App\Enums\Projects\IssueRelationType;
use App\Events\Projects\DependencyResolved;
use App\Exceptions\Projects\DependencyCycleException;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueRelation;
use App\Models\Projects\IssueWorkstream;
use App\Models\Projects\WorkstreamDependency;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Projects\BlockerResolvedNotification;
use App\Notifications\Projects\WorkstreamHandoffNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Blocking relations between issues and workstreams. Nodes are issues ("i:{id}") and workstreams
 * ("w:{id}"); an edge A → B means A waits for B. Besides explicit relations, every issue implicitly
 * waits for its required workstreams, which is what catches cross-issue cycles.
 */
class DependencyGraph
{
    protected const MAX_NODES = 2000;

    /**
     * Throw if making $from depend on $to would close a loop.
     */
    public function assertAcyclic(Issue|IssueWorkstream $from, Issue|IssueWorkstream $to): void
    {
        $path = $this->pathBetween($to, $from);

        if ($path !== null) {
            throw new DependencyCycleException(array_merge([$this->label($from)], $path));
        }
    }

    public function addWorkstreamDependency(IssueWorkstream $workstream, Issue|IssueWorkstream $on, ?User $actor, IssueActions $actions): WorkstreamDependency
    {
        $targetIssue = $on instanceof Issue ? $on : $on->issue;

        if ($targetIssue->organization_id !== $workstream->issue->organization_id) {
            throw ValidationException::withMessages(['depends_on' => 'Dependencies must stay within the organization.']);
        }

        if ($on instanceof IssueWorkstream && $on->id === $workstream->id) {
            throw ValidationException::withMessages(['depends_on' => 'A workstream cannot depend on itself.']);
        }

        if ($on instanceof Issue && $on->id === $workstream->issue_id) {
            throw ValidationException::withMessages(['depends_on' => 'A workstream cannot depend on its own issue.']);
        }

        $existing = WorkstreamDependency::query()
            ->where('workstream_id', $workstream->id)
            ->where($on instanceof Issue ? 'depends_on_issue_id' : 'depends_on_workstream_id', $on->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $dependency = DB::transaction(function () use ($workstream, $on, $actor, $actions) {
            $this->assertAcyclic($workstream, $on);

            $dependency = WorkstreamDependency::query()->create([
                'workstream_id' => $workstream->id,
                'depends_on_workstream_id' => $on instanceof IssueWorkstream ? $on->id : null,
                'depends_on_issue_id' => $on instanceof Issue ? $on->id : null,
                'created_by_id' => $actor?->id,
                'resolved_at' => $this->isTerminal($on) ? now() : null,
            ]);

            $this->recomputeBlocked($workstream);

            $actions->record($workstream->issue, IssueActivityType::DependencyAdded, $actor, [
                'team' => $workstream->team->name,
                'depends_on' => $this->label($on),
                'depends_on_issue' => $on instanceof Issue ? $on->identifier : $on->issue->identifier,
            ], $workstream);

            return $dependency;
        });

        $actions->broadcast($workstream->issue, 'dependencies');

        return $dependency;
    }

    public function removeWorkstreamDependency(WorkstreamDependency $dependency, ?User $actor, IssueActions $actions): void
    {
        $workstream = $dependency->workstream;
        $target = $dependency->dependsOnWorkstream ?? $dependency->dependsOnIssue;

        DB::transaction(function () use ($dependency, $workstream, $target, $actor, $actions) {
            $dependency->delete();
            $this->recomputeBlocked($workstream);

            $actions->record($workstream->issue, IssueActivityType::DependencyRemoved, $actor, [
                'team' => $workstream->team->name,
                'depends_on' => $target ? $this->label($target) : null,
            ], $workstream);
        });

        $actions->broadcast($workstream->issue, 'dependencies');
    }

    /**
     * Recount from the database; never increment blindly.
     */
    public function recomputeBlocked(Issue|IssueWorkstream $node): int
    {
        if ($node instanceof Issue) {
            $count = IssueRelation::query()
                ->where('related_issue_id', $node->id)
                ->where('type', IssueRelationType::Blocks->value)
                ->whereHas('issue', fn ($q) => $q->whereNotIn('status_category', ['completed', 'canceled']))
                ->count();

            Issue::query()->whereKey($node->id)->update(['blocked_by_count' => $count]);
            $node->blocked_by_count = $count;

            return $count;
        }

        $count = WorkstreamDependency::query()->where('workstream_id', $node->id)->whereNull('resolved_at')->count();
        IssueWorkstream::query()->whereKey($node->id)->update(['blocked_by_count' => $count]);
        $node->blocked_by_count = $count;

        Issue::query()->whereKey($node->issue_id)->update([
            'blocked_workstreams_count' => IssueWorkstream::query()->where('issue_id', $node->issue_id)->where('blocked_by_count', '>', 0)->count(),
        ]);

        return $count;
    }

    /**
     * A node reached a terminal status: release everything waiting on it.
     */
    public function resolveFor(Issue|IssueWorkstream $node, IssueActions $actions): void
    {
        $now = now();

        if ($node instanceof Issue) {
            WorkstreamDependency::query()->where('depends_on_issue_id', $node->id)->whereNull('resolved_at')->update(['resolved_at' => $now]);

            $dependentWorkstreams = IssueWorkstream::query()->whereIn('id', WorkstreamDependency::query()->where('depends_on_issue_id', $node->id)->where('resolved_at', $now)->pluck('workstream_id'))->with(['issue', 'team', 'assignees'])->get();

            $dependentIssues = Issue::query()->whereIn('id', IssueRelation::query()->where('issue_id', $node->id)->where('type', IssueRelationType::Blocks->value)->pluck('related_issue_id'))->get();
        } else {
            WorkstreamDependency::query()->where('depends_on_workstream_id', $node->id)->whereNull('resolved_at')->update(['resolved_at' => $now]);

            $dependentWorkstreams = IssueWorkstream::query()->whereIn('id', WorkstreamDependency::query()->where('depends_on_workstream_id', $node->id)->where('resolved_at', $now)->pluck('workstream_id'))->with(['issue', 'team', 'assignees'])->get();

            $dependentIssues = collect();
        }

        foreach ($dependentIssues as $issue) {
            if ($this->recomputeBlocked($issue) === 0) {
                $actions->record($issue, IssueActivityType::DependencyResolved, null, ['resolved_by' => $this->label($node)]);
                DependencyResolved::dispatch($issue, null, $node);

                if ($issue->owner) {
                    $issue->owner->notify(new BlockerResolvedNotification($issue, null, $this->label($node)));
                }

                $actions->broadcast($issue, 'blockers');
            }
        }

        foreach ($dependentWorkstreams as $workstream) {
            if ($this->recomputeBlocked($workstream) === 0) {
                $actions->record($workstream->issue, IssueActivityType::DependencyResolved, null, ['team' => $workstream->team->name, 'resolved_by' => $this->label($node)], $workstream);
                DependencyResolved::dispatch($workstream->issue, $workstream, $node);

                $recipients = $workstream->assignees->isNotEmpty() ? $workstream->assignees : $workstream->team->members()->wherePivot('role', 'lead')->get();
                $isHandoff = $node instanceof IssueWorkstream && $node->issue_id === $workstream->issue_id;

                if ($isHandoff) {
                    $actions->record($workstream->issue, IssueActivityType::Handoff, null, ['from_team' => $node->team->name, 'to_team' => $workstream->team->name], $workstream);
                    Notification::send($recipients, new WorkstreamHandoffNotification($workstream->issue, $workstream, $node));
                } else {
                    Notification::send($recipients, new BlockerResolvedNotification($workstream->issue, $workstream, $this->label($node)));
                }

                $actions->broadcast($workstream->issue, 'blockers');
            }
        }
    }

    /**
     * A finished node was reopened: its dependents are blocked again. Quiet, no notifications.
     */
    public function unresolveFor(Issue|IssueWorkstream $node, IssueActions $actions): void
    {
        $column = $node instanceof Issue ? 'depends_on_issue_id' : 'depends_on_workstream_id';

        $dependencies = WorkstreamDependency::query()->where($column, $node->id)->whereNotNull('resolved_at')->with(['workstream.issue', 'workstream.team'])->get();

        foreach ($dependencies as $dependency) {
            $dependency->forceFill(['resolved_at' => null])->save();
            $this->recomputeBlocked($dependency->workstream);
            $actions->record($dependency->workstream->issue, IssueActivityType::DependencyReopened, null, ['team' => $dependency->workstream->team->name, 'reopened_by' => $this->label($node)], $dependency->workstream);
            $actions->broadcast($dependency->workstream->issue, 'blockers');
        }

        if ($node instanceof Issue) {
            $dependents = Issue::query()->whereIn('id', IssueRelation::query()->where('issue_id', $node->id)->where('type', IssueRelationType::Blocks->value)->pluck('related_issue_id'))->get();

            foreach ($dependents as $issue) {
                $this->recomputeBlocked($issue);
                $actions->record($issue, IssueActivityType::DependencyReopened, null, ['reopened_by' => $node->identifier]);
                $actions->broadcast($issue, 'blockers');
            }
        }
    }

    /**
     * Before a workstream is deleted: its dependents must not stay blocked by a ghost.
     */
    public function onNodeRemoved(IssueWorkstream $workstream, IssueActions $actions): void
    {
        $dependents = IssueWorkstream::query()->whereIn('id', WorkstreamDependency::query()->where('depends_on_workstream_id', $workstream->id)->pluck('workstream_id'))->get();

        WorkstreamDependency::query()->where('depends_on_workstream_id', $workstream->id)->delete();

        foreach ($dependents as $dependent) {
            $this->recomputeBlocked($dependent);
        }
    }

    /**
     * Unresolved things this node waits for, as display rows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function blockersOf(Issue|IssueWorkstream $node): Collection
    {
        if ($node instanceof Issue) {
            return IssueRelation::query()
                ->where('related_issue_id', $node->id)
                ->where('type', IssueRelationType::Blocks->value)
                ->with('issue:id,uuid,identifier,title,status_category,status_id')
                ->get()
                ->map(fn (IssueRelation $r) => $this->row(null, 'issue', $r->issue, null, $r->issue->status_category->isTerminal()));
        }

        return WorkstreamDependency::query()
            ->where('workstream_id', $node->id)
            ->with(['dependsOnWorkstream.issue', 'dependsOnWorkstream.team', 'dependsOnIssue'])
            ->get()
            ->map(fn (WorkstreamDependency $d) => $this->row(
                $d->id,
                $d->depends_on_workstream_id ? 'workstream' : 'issue',
                $d->dependsOnWorkstream ? $d->dependsOnWorkstream->issue : $d->dependsOnIssue,
                $d->dependsOnWorkstream?->team,
                $d->resolved_at !== null,
            ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(?int $id, string $kind, ?Issue $issue, ?Team $team, bool $resolved): array
    {
        return ['id' => $id, 'kind' => $kind, 'issue' => $issue, 'team' => $team, 'resolved' => $resolved];
    }

    /**
     * Breadth-first search over "waits for" edges from $start, returning the labelled path to
     * $target or null when unreachable.
     *
     * @return array<int, string>|null
     */
    protected function pathBetween(Issue|IssueWorkstream $start, Issue|IssueWorkstream $target): ?array
    {
        $startKey = $this->key($start);
        $targetKey = $this->key($target);

        if ($startKey === $targetKey) {
            return [$this->label($start)];
        }

        $visited = [$startKey => true];
        $parents = [];
        $frontier = [$startKey];
        $labels = [$startKey => $this->label($start)];

        while ($frontier !== []) {
            if (count($visited) > self::MAX_NODES) {
                throw new RuntimeException('The dependency chain is too long to check.');
            }

            $next = [];

            foreach ($this->neighbours($frontier) as $from => $edges) {
                foreach ($edges as [$key, $label]) {
                    if (isset($visited[$key])) {
                        continue;
                    }

                    $visited[$key] = true;
                    $parents[$key] = $from;
                    $labels[$key] = $label;

                    if ($key === $targetKey) {
                        $path = [];
                        $cursor = $key;

                        while ($cursor !== null) {
                            array_unshift($path, $labels[$cursor]);
                            $cursor = $parents[$cursor] ?? null;
                        }

                        return array_reverse($path);
                    }

                    $next[] = $key;
                }
            }

            $frontier = $next;
        }

        return null;
    }

    /**
     * For each node key, the nodes it waits for: explicit blockers, workstream dependencies and
     * (for issues) their required workstreams. Batched per level.
     *
     * @param  array<int, string>  $keys
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    protected function neighbours(array $keys): array
    {
        $issueIds = [];
        $workstreamIds = [];

        foreach ($keys as $key) {
            [$type, $id] = explode(':', $key);
            $type === 'i' ? $issueIds[] = (int) $id : $workstreamIds[] = (int) $id;
        }

        $result = array_fill_keys($keys, []);

        if ($issueIds !== []) {
            $relations = IssueRelation::query()->whereIn('related_issue_id', $issueIds)->where('type', IssueRelationType::Blocks->value)->with('issue:id,identifier')->get();

            foreach ($relations as $relation) {
                $result['i:'.$relation->related_issue_id][] = ['i:'.$relation->issue_id, $relation->issue->identifier];
            }

            $workstreams = IssueWorkstream::query()->whereIn('issue_id', $issueIds)->where('is_required', true)->with(['team:id,name', 'issue:id,identifier'])->get();

            foreach ($workstreams as $workstream) {
                $result['i:'.$workstream->issue_id][] = ['w:'.$workstream->id, $workstream->issue->identifier.' / '.$workstream->team->name];
            }
        }

        if ($workstreamIds !== []) {
            $dependencies = WorkstreamDependency::query()->whereIn('workstream_id', $workstreamIds)->with(['dependsOnWorkstream.team:id,name', 'dependsOnWorkstream.issue:id,identifier', 'dependsOnIssue:id,identifier'])->get();

            foreach ($dependencies as $dependency) {
                if ($dependency->dependsOnWorkstream) {
                    $result['w:'.$dependency->workstream_id][] = ['w:'.$dependency->depends_on_workstream_id, $dependency->dependsOnWorkstream->issue->identifier.' / '.$dependency->dependsOnWorkstream->team->name];
                } elseif ($dependency->dependsOnIssue) {
                    $result['w:'.$dependency->workstream_id][] = ['i:'.$dependency->depends_on_issue_id, $dependency->dependsOnIssue->identifier];
                }
            }
        }

        return $result;
    }

    protected function key(Issue|IssueWorkstream $node): string
    {
        return ($node instanceof Issue ? 'i:' : 'w:').$node->id;
    }

    protected function label(Issue|IssueWorkstream $node): string
    {
        return $node instanceof Issue ? $node->identifier : $node->issue->identifier.' / '.$node->team->name;
    }

    protected function isTerminal(Issue|IssueWorkstream $node): bool
    {
        return $node->status_category->isTerminal();
    }
}
