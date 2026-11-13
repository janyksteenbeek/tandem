<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\Concerns\BuildsIssueLists;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;
use App\Services\Projects\IssueLookups;
use App\Services\Projects\IssueQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MyIssuesController extends Controller
{
    use BuildsIssueLists;

    public const BUCKETS = ['assigned', 'owned', 'workstreams', 'created', 'subscribed', 'due_soon', 'overdue', 'completed', 'recent'];

    public function __construct(protected IssueLookups $lookups) {}

    public function index(Request $request, Organization $currentOrganization): Response
    {
        $user = $request->user();
        $bucket = in_array($request->query('bucket'), self::BUCKETS, true) ? $request->query('bucket') : 'assigned';
        $display = $this->display($request, ['group_by' => 'status', 'show_completed' => $bucket === 'completed' ? 'all' : 'none']);
        $filters = $this->filters($request);

        return Inertia::render('projects/my-issues/Index', [
            'bucket' => $bucket,
            'buckets' => Inertia::defer(fn () => $this->bucketCounts($user)),
            'filters' => $filters,
            'display' => $display,
            'issues' => Inertia::merge(fn () => IssueListItemResource::collection(
                $this->bucketQuery($user, $bucket, $filters, $display)->cursorPaginate(50)->withQueryString()
            )->response($request)->getData(true))->matchOn('data.uuid'),
            'lookups' => fn () => $this->lookups->for($user, $currentOrganization),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $display
     * @return Builder<Issue>
     */
    protected function bucketQuery(User $user, string $bucket, array $filters, array $display): Builder
    {
        $query = $this->issueQuery($user, $filters, $display);

        if ($bucket === 'recent') {
            return $query->join('recent_views', function ($join) use ($user) {
                $join->on('recent_views.viewable_id', '=', 'issues.id')
                    ->where('recent_views.viewable_type', 'issue')
                    ->where('recent_views.user_id', $user->id);
            })->select('issues.*')->reorder()->orderByDesc('recent_views.viewed_at')->orderByDesc('issues.id');
        }

        $this->applyBucket($query, $user, $bucket);

        return $query;
    }

    /**
     * @param  Builder<Issue>  $query
     */
    protected function applyBucket(Builder $query, User $user, string $bucket): void
    {
        $assigned = fn (Builder $q) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('issue_workstream_assignees')
            ->join('issue_workstreams', 'issue_workstreams.id', '=', 'issue_workstream_assignees.workstream_id')
            ->whereColumn('issue_workstreams.issue_id', 'issues.id')
            ->where('issue_workstream_assignees.user_id', $user->id));

        $mine = fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('issues.owner_id', $user->id)->orWhere(fn (Builder $a) => $assigned($a)));

        match ($bucket) {
            'assigned' => $assigned($query),
            'owned' => $query->where('issues.owner_id', $user->id),
            'workstreams' => $query->whereHas('workstreams', fn (Builder $w) => $w->whereIn('team_id', app(IssueAccess::class)->teamIds($user, (int) $user->current_organization_id))->whereNotIn('status_category', ['completed', 'canceled'])),
            'created' => $query->where('issues.created_by_id', $user->id),
            'subscribed' => $query->whereHas('followers', fn (Builder $f) => $f->whereKey($user->id)),
            'due_soon' => $mine($query)->whereNotNull('issues.due_date')->whereBetween('issues.due_date', [now()->toDateString(), now()->addDays(7)->toDateString()]),
            'overdue' => $mine($query)->whereNotNull('issues.due_date')->where('issues.due_date', '<', now()->toDateString())->whereNotIn('issues.status_category', ['completed', 'canceled']),
            'completed' => $mine($query)->where('issues.status_category', 'completed'),
            default => null,
        };
    }

    /**
     * @return array<string, int>
     */
    protected function bucketCounts(User $user): array
    {
        $counts = [];

        foreach (self::BUCKETS as $bucket) {
            if ($bucket === 'recent') {
                continue;
            }

            $query = app(IssueQuery::class)->build($user, [], [], $bucket === 'completed');
            $this->applyBucket($query, $user, $bucket);
            $counts[$bucket] = (clone $query)->reorder()->count();
        }

        return $counts;
    }
}
