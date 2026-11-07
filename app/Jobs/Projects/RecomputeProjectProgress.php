<?php

namespace App\Jobs\Projects;

use App\Events\Projects\Broadcast\ProjectUpdated;
use App\Models\Projects\Project;
use App\Services\Projects\ProjectProgress;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Debounces bursts of issue changes into one progress recompute per project.
 */
class RecomputeProjectProgress implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 30;

    public function __construct(public int $projectId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(ProjectProgress $progress): void
    {
        $project = Project::query()->find($this->projectId);

        if (! $project) {
            return;
        }

        $progress->recompute($project);
        ProjectUpdated::dispatch($project, 'progress');
    }
}
