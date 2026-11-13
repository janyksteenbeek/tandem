<?php

namespace App\Services\Projects;

use App\Enums\Projects\StatusCategory;
use App\Models\Projects\Milestone;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Projects\Release;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives progress from real issue completion. Canceled work is excluded from the denominator;
 * weights follow estimates when every counted issue has one.
 */
class ProjectProgress
{
    /**
     * @return array<string, mixed>
     */
    public function recompute(Project $project): array
    {
        $rows = DB::table('issues')
            ->selectRaw('status_category, COUNT(*) AS aggregate, SUM(COALESCE(estimate, 0)) AS points, SUM(estimate IS NULL) AS unestimated')
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->groupBy('status_category')
            ->get()
            ->keyBy('status_category');

        $count = fn (string $category) => (int) ($rows[$category]->aggregate ?? 0);
        $points = fn (string $category) => (float) ($rows[$category]->points ?? 0);

        $total = array_sum(array_map($count, array_map(fn (StatusCategory $c) => $c->value, StatusCategory::cases())));
        $canceled = $count('canceled');
        $completed = $count('completed');
        $started = $count('started');
        $counted = $total - $canceled;
        $unestimated = (int) $rows->sum('unestimated');

        $pointsTotal = array_sum(array_map($points, ['backlog', 'unstarted', 'started', 'completed']));
        $pointsDone = $points('completed');

        $percent = $counted > 0
            ? ($unestimated === 0 && $pointsTotal > 0 ? round($pointsDone / $pointsTotal * 100, 1) : round($completed / $counted * 100, 1))
            : 0.0;

        $byTeam = DB::table('issue_workstreams')
            ->join('issues', 'issues.id', '=', 'issue_workstreams.issue_id')
            ->join('teams', 'teams.id', '=', 'issue_workstreams.team_id')
            ->selectRaw('teams.id AS team_id, teams.name AS team, COUNT(*) AS workstreams, SUM(issue_workstreams.status_category = "completed") AS completed')
            ->where('issues.project_id', $project->id)
            ->whereNull('issues.deleted_at')
            ->groupBy('teams.id', 'teams.name')
            ->get()
            ->map(fn ($row) => ['team_id' => (int) $row->team_id, 'team' => $row->team, 'workstreams' => (int) $row->workstreams, 'completed' => (int) $row->completed])
            ->values()
            ->all();

        $progress = [
            'total' => $total,
            'backlog' => $count('backlog'),
            'unstarted' => $count('unstarted'),
            'started' => $started,
            'completed' => $completed,
            'canceled' => $canceled,
            'percent' => $percent,
            'estimate_total' => $pointsTotal,
            'estimate_done' => $pointsDone,
            'by_team' => $byTeam,
            'computed_at' => now()->toISOString(),
        ];

        $project->forceFill([
            'issues_count' => $total,
            'completed_issues_count' => $completed,
            'canceled_issues_count' => $canceled,
            'started_issues_count' => $started,
            'progress' => $progress,
        ])->saveQuietly();

        foreach ($project->milestones as $milestone) {
            $this->recomputeMilestone($milestone);
        }

        return $progress;
    }

    public function recomputeMilestone(Milestone $milestone): void
    {
        $row = DB::table('issues')
            ->selectRaw('COUNT(*) AS total, SUM(status_category = "completed") AS completed, SUM(status_category = "canceled") AS canceled')
            ->where('milestone_id', $milestone->id)
            ->whereNull('deleted_at')
            ->first();

        $milestone->forceFill([
            'issues_count' => (int) ($row->total ?? 0) - (int) ($row->canceled ?? 0),
            'completed_issues_count' => (int) ($row->completed ?? 0),
        ])->saveQuietly();
    }

    public function recomputeRelease(Release $release): void
    {
        $row = DB::table('issue_release')
            ->join('issues', 'issues.id', '=', 'issue_release.issue_id')
            ->selectRaw('COUNT(*) AS total, SUM(issues.status_category = "completed") AS completed')
            ->where('issue_release.release_id', $release->id)
            ->whereNull('issues.deleted_at')
            ->first();

        $release->forceFill(['issues_count' => (int) ($row->total ?? 0), 'completed_issues_count' => (int) ($row->completed ?? 0)])->saveQuietly();
    }

    public function recomputeProduct(Product $product): void
    {
        $row = DB::table('issues')
            ->selectRaw('COUNT(*) AS total, SUM(status_category NOT IN ("completed", "canceled")) AS open')
            ->where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->first();

        $product->forceFill(['issues_count' => (int) ($row->total ?? 0), 'open_issues_count' => (int) ($row->open ?? 0)])->saveQuietly();

        DB::table('modules')->where('product_id', $product->id)->update([
            'issues_count' => DB::raw('(SELECT COUNT(*) FROM issue_module JOIN issues ON issues.id = issue_module.issue_id WHERE issue_module.module_id = modules.id AND issues.deleted_at IS NULL)'),
            'open_issues_count' => DB::raw('(SELECT COUNT(*) FROM issue_module JOIN issues ON issues.id = issue_module.issue_id WHERE issue_module.module_id = modules.id AND issues.deleted_at IS NULL AND issues.status_category NOT IN ("completed", "canceled"))'),
        ]);
    }

    /**
     * Aggregate a product's projects from their cached progress; never rescans issues.
     *
     * @return array<string, mixed>
     */
    public function forProduct(Product $product): array
    {
        return $this->aggregate($product->projects()->get());
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>
     */
    public function aggregate($projects): array
    {
        $total = $projects->sum(fn (Project $p) => (int) ($p->progress['total'] ?? 0));
        $canceled = $projects->sum(fn (Project $p) => (int) ($p->progress['canceled'] ?? 0));
        $completed = $projects->sum(fn (Project $p) => (int) ($p->progress['completed'] ?? 0));
        $counted = $total - $canceled;

        return [
            'projects' => $projects->count(),
            'active' => $projects->filter(fn (Project $p) => in_array($p->status->value, ['planned', 'in_progress'], true))->count(),
            'total' => $total,
            'completed' => $completed,
            'percent' => $counted > 0 ? round($completed / $counted * 100, 1) : 0.0,
        ];
    }
}
