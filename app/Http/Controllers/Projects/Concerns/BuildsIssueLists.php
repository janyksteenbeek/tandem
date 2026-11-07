<?php

namespace App\Http\Controllers\Projects\Concerns;

use App\Http\Resources\Projects\IssueListItemResource;
use App\Models\Projects\Issue;
use App\Models\Projects\WorkflowStatus;
use App\Models\User;
use App\Services\Projects\IssueFilterCatalog;
use App\Services\Projects\IssueQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * Shared plumbing for every page that shows an issue list, board, calendar or timeline: parse
 * display options from the query string, build the query, and produce merge/optional props.
 */
trait BuildsIssueLists
{
    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    protected function display(Request $request, array $defaults = []): array
    {
        $display = array_merge([
            'mode' => 'list',
            'group_by' => 'status',
            'sort' => ['field' => 'updated_at', 'dir' => 'desc'],
            'show_completed' => 'recent',
            'columns' => null,
        ], $defaults);

        if (in_array($request->query('mode'), ['list', 'board', 'calendar', 'timeline'], true)) {
            $display['mode'] = $request->query('mode');
        }

        if (in_array($request->query('group_by'), IssueFilterCatalog::GROUPS, true)) {
            $display['group_by'] = $request->query('group_by');
        }

        if (in_array($request->query('sort'), IssueFilterCatalog::SORTS, true)) {
            $display['sort'] = ['field' => $request->query('sort'), 'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc'];
        }

        if (in_array($request->query('show_completed'), ['all', 'recent', 'none'], true)) {
            $display['show_completed'] = $request->query('show_completed');
        }

        return $display;
    }

    /**
     * Filters come as JSON in `filters` (the structured AND/OR shape) or as a plain `q`.
     *
     * @param  array<int, array<string, mixed>>  $base
     * @return array<string, mixed>
     */
    protected function filters(Request $request, array $base = []): array
    {
        $raw = $request->query('filters');
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        $filters = app(IssueFilterCatalog::class)->normalize(is_array($decoded) ? $decoded : []);

        if ($request->filled('q')) {
            $filters['q'] = (string) $request->query('q');
        }

        if ($base !== []) {
            $filters['groups'] = array_merge([['match' => 'all', 'clauses' => $base]], $filters['groups']);
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $display
     * @return Builder<Issue>
     */
    protected function issueQuery(User $user, array $filters, array $display): Builder
    {
        $includeTerminal = $display['show_completed'] !== 'none';
        $query = app(IssueQuery::class)->build($user, $filters, $display['sort'], $includeTerminal);

        if ($display['show_completed'] === 'recent') {
            $query->where(fn (Builder $q) => $q->whereNotIn('issues.status_category', ['completed', 'canceled'])->orWhere('issues.updated_at', '>=', now()->subDays(14)));
        }

        return $query;
    }

    /**
     * The merge-paginated list prop.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $display
     */
    protected function issuesProp(Request $request, User $user, array $filters, array $display, int $perPage = 50): mixed
    {
        return Inertia::merge(fn () => IssueListItemResource::collection(
            $this->issueQuery($user, $filters, $display)->cursorPaginate($perPage)->withQueryString()
        )->response($request)->getData(true))->matchOn('data.uuid');
    }

    /**
     * Board columns: one per status of the given workflow, each with its own cursor page.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $display
     * @param  Collection<int, WorkflowStatus>  $statuses
     * @return array<int, array<string, mixed>>
     */
    protected function boardColumns(Request $request, User $user, array $filters, array $display, $statuses, ?int $teamId = null): array
    {
        $columns = [];

        foreach ($statuses as $status) {
            $query = $this->issueQuery($user, $filters, array_merge($display, ['sort' => ['field' => 'manual', 'dir' => 'asc'], 'show_completed' => 'all']));

            if ($teamId) {
                $query->whereHas('workstreams', fn (Builder $w) => $w->where('team_id', $teamId)->where('status_id', $status->id));
            } else {
                $query->where('issues.status_id', $status->id);
            }

            if ($status->category->isTerminal()) {
                $query->where('issues.updated_at', '>=', now()->subDays(30));
            }

            $page = $query->cursorPaginate(40, ['*'], 'cursor_'.$status->id);

            $columns[] = [
                'status_id' => $status->id,
                'issues' => IssueListItemResource::collection($page->items())->resolve(),
                'total' => (clone $query)->reorder()->count(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ];
        }

        return $columns;
    }
}
