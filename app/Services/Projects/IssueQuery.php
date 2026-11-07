<?php

namespace App\Services\Projects;

use App\Enums\Projects\IssuePriority;
use App\Models\Projects\Issue;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds issue lists: visibility, nested AND/OR filter groups, search, whitelisted sorting and
 * deterministic cursor pagination. Every list, board column and count in the app comes from here.
 */
class IssueQuery
{
    public const EAGER = ['status', 'owner', 'leadTeam', 'labels', 'project.lead', 'product', 'modules', 'parent', 'milestone', 'workstreams.team', 'workstreams.status', 'workstreams.assignees', 'workstreams.cycle'];

    public function __construct(protected IssueFilterCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $filters  Normalised by IssueFilterCatalog::normalize.
     * @param  array{field?: string, dir?: string}  $sort
     * @return Builder<Issue>
     */
    public function build(User $user, array $filters = [], array $sort = [], bool $includeTerminal = true): Builder
    {
        $query = Issue::query()->visibleTo($user)->with(self::EAGER);

        if (! $includeTerminal) {
            $query->whereNotIn('issues.status_category', ['completed', 'canceled']);
        }

        $normalized = $this->catalog->normalize($filters);

        if ($normalized['q']) {
            $query->search($normalized['q']);
        }

        $this->applyGroups($query, $normalized['groups'], $normalized['match'], $user);
        $this->applySort($query, $sort);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{field?: string, dir?: string}  $sort
     * @return CursorPaginator<int, Issue>
     */
    public function paginate(User $user, array $filters = [], array $sort = [], int $perPage = 50, bool $includeTerminal = true): CursorPaginator
    {
        return $this->build($user, $filters, $sort, $includeTerminal)->cursorPaginate(min(max($perPage, 1), 200))->withQueryString();
    }

    /**
     * @param  array<int, array{match: string, clauses: array<int, array{field: string, op: string, value: mixed}>}>  $groups
     * @param  Builder<Issue>  $query
     */
    public function applyGroups(Builder $query, array $groups, string $match, User $user): void
    {
        if ($groups === []) {
            return;
        }

        $query->where(function (Builder $outer) use ($groups, $match, $user) {
            foreach ($groups as $index => $group) {
                $method = $index === 0 || $match === 'all' ? 'where' : 'orWhere';

                $outer->{$method}(function (Builder $inner) use ($group, $user) {
                    foreach ($group['clauses'] as $i => $clause) {
                        $clauseMethod = $i === 0 || $group['match'] === 'all' ? 'where' : 'orWhere';
                        $inner->{$clauseMethod}(fn (Builder $q) => $this->applyClause($q, $clause, $user));
                    }
                });
            }
        });
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  array{field: string, op: string, value: mixed}  $clause
     */
    protected function applyClause(Builder $query, array $clause, User $user): void
    {
        $field = $clause['field'];
        $op = $clause['op'];
        $values = $this->values($clause['value'] ?? null, $user);
        $negate = in_array($op, ['not_in', 'not_contains', 'is_false'], true);

        switch ($field) {
            case 'status':
                $this->whereIdIn($query, 'issues.status_id', $op, $values);
                break;
            case 'status_category':
                $this->whereIdIn($query, 'issues.status_category', $op, $values, cast: false);
                break;
            case 'priority':
                $this->whereIdIn($query, 'issues.priority', $op, array_map(fn ($v) => IssuePriority::fromKey((string) $v)->value, $values));
                break;
            case 'owner':
                $this->whereNullable($query, 'issues.owner_id', $op, $values);
                break;
            case 'lead_team':
                $this->whereNullable($query, 'issues.lead_team_id', $op, $values);
                break;
            case 'product':
                $this->whereNullable($query, 'issues.product_id', $op, $values);
                break;
            case 'project':
                $this->whereNullable($query, 'issues.project_id', $op, $values);
                break;
            case 'milestone':
                $this->whereNullable($query, 'issues.milestone_id', $op, $values);
                break;
            case 'parent':
                $this->whereNullable($query, 'issues.parent_id', $op, $values);
                break;
            case 'created_by':
                $this->whereIdIn($query, 'issues.created_by_id', $op, $values);
                break;
            case 'assignee':
                $this->whereRelation($query, $op, $values, fn (Builder $q, $ids) => $q->whereHas('workstreams.assignees', fn (Builder $a) => $a->whereIn('users.id', $ids)), fn (Builder $q) => $q->whereHas('workstreams.assignees'));
                break;
            case 'team':
                $this->whereRelation($query, $op, $values, fn (Builder $q, $ids) => $q->whereHas('workstreams', fn (Builder $w) => $w->whereIn('team_id', $ids)), fn (Builder $q) => $q->whereHas('workstreams'));
                break;
            case 'module':
                $this->whereRelation($query, $op, $values, fn (Builder $q, $ids) => $q->whereHas('modules', fn (Builder $m) => $m->whereIn('modules.id', $ids)), fn (Builder $q) => $q->whereHas('modules'));
                break;
            case 'label':
                $this->whereRelation($query, $op, $values, fn (Builder $q, $ids) => $q->whereHas('labels', fn (Builder $l) => $l->whereIn('issue_labels.id', $ids)), fn (Builder $q) => $q->whereHas('labels'));
                break;
            case 'cycle':
                $this->whereRelation($query, $op, $values, fn (Builder $q, $ids) => $q->whereHas('workstreams', fn (Builder $w) => $w->whereIn('cycle_id', $ids)), fn (Builder $q) => $q->whereHas('workstreams', fn (Builder $w) => $w->whereNotNull('cycle_id')));
                break;
            case 'release':
                $this->whereRelation($query, $op, $values, fn (Builder $q, $ids) => $q->whereHas('releases', fn (Builder $r) => $r->whereIn('releases.id', $ids)), fn (Builder $q) => $q->whereHas('releases'));
                break;
            case 'follower':
                $query->whereHas('followers', fn (Builder $f) => $f->whereIn('users.id', $values));
                break;
            case 'blocked':
                $negate ? $query->where('issues.blocked_by_count', 0)->where('issues.blocked_workstreams_count', 0)
                    : $query->where(fn (Builder $q) => $q->where('issues.blocked_by_count', '>', 0)->orWhere('issues.blocked_workstreams_count', '>', 0));
                break;
            case 'ready_to_complete':
                $query->where('issues.is_ready_to_complete', ! $negate);
                break;
            case 'has_sub_issues':
                $negate ? $query->where('issues.sub_issues_count', 0) : $query->where('issues.sub_issues_count', '>', 0);
                break;
            case 'due_date':
            case 'start_date':
            case 'created_at':
            case 'updated_at':
            case 'completed_at':
                $this->whereDate($query, 'issues.'.$field, $op, $values);
                break;
            case 'estimate':
                match ($op) {
                    'eq' => $query->where('issues.estimate', (float) ($values[0] ?? 0)),
                    'gt' => $query->where('issues.estimate', '>', (float) ($values[0] ?? 0)),
                    'lt' => $query->where('issues.estimate', '<', (float) ($values[0] ?? 0)),
                    'is_empty' => $query->whereNull('issues.estimate'),
                    'is_not_empty' => $query->whereNotNull('issues.estimate'),
                    default => null,
                };
                break;
            case 'title':
                $term = '%'.(string) ($values[0] ?? '').'%';
                $negate ? $query->where('issues.title', 'not like', $term) : $query->where('issues.title', 'like', $term);
                break;
        }
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  array{field?: string, dir?: string}  $sort
     */
    public function applySort(Builder $query, array $sort): void
    {
        $field = in_array($sort['field'] ?? null, IssueFilterCatalog::SORTS, true) ? $sort['field'] : 'updated_at';
        $dir = ($sort['dir'] ?? null) === 'asc' ? 'asc' : 'desc';

        match ($field) {
            'manual' => $query->orderByRaw('issues.sort_order IS NULL')->orderBy('issues.sort_order', $dir),
            'priority' => $query->orderBy('issues.priority', $dir === 'asc' ? 'asc' : 'desc'),
            'status' => $query->orderBy(DB::raw('(SELECT position FROM workflow_statuses WHERE workflow_statuses.id = issues.status_id)'), $dir),
            'identifier' => $query->orderBy('issues.prefix', $dir)->orderBy('issues.number', $dir),
            'due_date' => $query->orderByRaw('issues.due_date IS NULL')->orderBy('issues.due_date', $dir),
            'estimate' => $query->orderByRaw('issues.estimate IS NULL')->orderBy('issues.estimate', $dir),
            default => $query->orderBy('issues.'.$field, $dir),
        };

        $query->orderBy('issues.id', $dir);
    }

    /**
     * Counts per group key for the current filters, for board headers and grouped lists.
     *
     * @param  Builder<Issue>  $query
     * @return array<string, int>
     */
    public function groupCounts(Builder $query, string $groupBy): array
    {
        $base = (clone $query)->reorder()->without(self::EAGER);

        $column = match ($groupBy) {
            'status' => 'issues.status_id',
            'status_category' => 'issues.status_category',
            'priority' => 'issues.priority',
            'owner' => 'issues.owner_id',
            'project' => 'issues.project_id',
            'product' => 'issues.product_id',
            'milestone' => 'issues.milestone_id',
            default => null,
        };

        if ($column === null) {
            return [];
        }

        return $base->selectRaw("$column AS grp, COUNT(*) AS aggregate")
            ->groupBy(DB::raw($column))
            ->pluck('aggregate', 'grp')
            ->mapWithKeys(fn ($count, $key) => [(string) $key === '' ? 'none' : (string) $key => (int) $count])
            ->all();
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  array<int, mixed>  $values
     */
    protected function whereIdIn(Builder $query, string $column, string $op, array $values, bool $cast = true): void
    {
        $values = $cast ? array_map('intval', $values) : $values;

        $op === 'not_in' ? $query->whereNotIn($column, $values) : $query->whereIn($column, $values);
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  array<int, mixed>  $values
     */
    protected function whereNullable(Builder $query, string $column, string $op, array $values): void
    {
        match ($op) {
            'is_empty' => $query->whereNull($column),
            'is_not_empty' => $query->whereNotNull($column),
            'not_in' => $query->where(fn (Builder $q) => $q->whereNotIn($column, array_map('intval', $values))->orWhereNull($column)),
            default => $query->whereIn($column, array_map('intval', $values)),
        };
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  array<int, mixed>  $values
     * @param  callable(Builder<Issue>, array<int, int>): mixed  $in
     * @param  callable(Builder<Issue>): mixed  $exists
     */
    protected function whereRelation(Builder $query, string $op, array $values, callable $in, callable $exists): void
    {
        $ids = array_map('intval', $values);

        match ($op) {
            'is_empty' => $query->whereNot(fn (Builder $q) => $exists($q)),
            'is_not_empty' => $exists($query),
            'not_in' => $query->whereNot(fn (Builder $q) => $in($q, $ids)),
            default => $in($query, $ids),
        };
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  array<int, mixed>  $values
     */
    protected function whereDate(Builder $query, string $column, string $op, array $values): void
    {
        match ($op) {
            'before' => $query->where($column, '<=', $this->date($values[0] ?? null)),
            'after' => $query->where($column, '>=', $this->date($values[0] ?? null)),
            'between' => $query->whereBetween($column, [$this->date($values[0] ?? null), $this->date($values[1] ?? null)]),
            'is_empty' => $query->whereNull($column),
            'is_not_empty' => $query->whereNotNull($column),
            'overdue' => $query->whereNotNull($column)->where($column, '<', now()->toDateString())->whereNotIn('issues.status_category', ['completed', 'canceled']),
            default => null,
        };
    }

    protected function date(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return match ($value) {
            'today' => now()->toDateString(),
            'tomorrow' => now()->addDay()->toDateString(),
            'week' => now()->addWeek()->toDateString(),
            'month' => now()->addMonth()->toDateString(),
            default => $value !== '' ? $value : now()->toDateString(),
        };
    }

    /**
     * "me" resolves to the current user; everything else passes through as a list.
     *
     * @return array<int, mixed>
     */
    protected function values(mixed $value, User $user): array
    {
        $values = is_array($value) ? $value : ($value === null ? [] : [$value]);

        return array_values(array_map(fn ($v) => $v === 'me' ? $user->id : $v, $values));
    }
}
