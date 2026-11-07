<?php

namespace App\Services\Projects;

use App\Enums\Projects\IssueActivityType;
use App\Enums\Projects\StatusCategory;
use App\Events\Projects\IssueCompletionRecomputed;
use App\Models\Projects\Issue;
use App\Models\User;
use App\Services\Projects\Data\CompletionReadiness;
use Illuminate\Support\Facades\DB;

/**
 * Derives what the workstreams say about an issue and applies forward-only overall transitions.
 * Team statuses never pull the overall status backwards and a finished issue is never reopened
 * automatically; conflicts are surfaced as activity and notifications instead.
 */
class IssueCompletion
{
    public function __construct(protected WorkflowActions $workflows) {}

    public function evaluate(Issue $issue): CompletionReadiness
    {
        $row = DB::table('issue_workstreams')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(is_required) AS required_count')
            ->selectRaw("SUM(is_required AND status_category = 'completed') AS required_completed")
            ->selectRaw("SUM(is_required AND status_category = 'canceled') AS required_canceled")
            ->selectRaw("SUM(status_category = 'completed') AS completed")
            ->selectRaw("SUM(status_category = 'started') AS started")
            ->selectRaw('SUM(blocked_by_count > 0) AS blocked')
            ->where('issue_id', $issue->id)
            ->first();

        $total = (int) ($row->total ?? 0);
        $required = (int) ($row->required_count ?? 0);
        $requiredCompleted = (int) ($row->required_completed ?? 0);
        $requiredCanceled = (int) ($row->required_canceled ?? 0);
        $completed = (int) ($row->completed ?? 0);
        $started = (int) ($row->started ?? 0);
        $blocked = (int) ($row->blocked ?? 0);

        $requiredTerminal = $required > 0 && ($requiredCompleted + $requiredCanceled) === $required;
        $ready = $requiredTerminal && $requiredCompleted > 0;

        $suggested = match (true) {
            $total === 0 => null,
            $requiredTerminal && $requiredCompleted > 0 => StatusCategory::Completed,
            $requiredTerminal && $requiredCanceled === $required => StatusCategory::Canceled,
            $started > 0 || $completed > 0 => StatusCategory::Started,
            default => StatusCategory::Unstarted,
        };

        return new CompletionReadiness($total, $required, $requiredCompleted, $requiredCanceled, $completed, $started, $blocked, $ready, $suggested);
    }

    /**
     * Refresh counters and apply the forward-only overall transitions. Must run after every
     * workstream change; serialised per issue through a row lock.
     */
    public function recompute(Issue $issue, IssueActions $actions, ?User $actor = null, string $reason = 'workstreams'): CompletionReadiness
    {
        return DB::transaction(function () use ($issue, $actions, $actor, $reason) {
            $locked = Issue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $wasReady = $locked->is_ready_to_complete;

            $readiness = $this->evaluate($locked);
            $workflow = $this->workflows->issueWorkflowFor($locked->organization, $locked->product);

            $suggestedStatus = $readiness->suggested ? $workflow->statusFor($readiness->suggested) : null;

            $locked->forceFill([
                'workstreams_count' => $readiness->total,
                'workstreams_required_count' => $readiness->required,
                'workstreams_completed_count' => $readiness->completed,
                'blocked_workstreams_count' => $readiness->blocked,
                'is_ready_to_complete' => $readiness->ready,
                'suggested_status_id' => $suggestedStatus?->id,
            ])->saveQuietly();

            $category = $locked->status_category;

            if ($category->isTerminal()) {
                if ($wasReady && ! $readiness->ready && $category === StatusCategory::Completed && $readiness->total > 0) {
                    $actions->record($locked, IssueActivityType::CompletionConflict, $actor, ['readiness' => $readiness->toArray(), 'reason' => $reason]);
                    $actions->notifyCompletionConflict($locked, $readiness);
                }
            } else {
                if (in_array($category, [StatusCategory::Backlog, StatusCategory::Unstarted], true)
                    && $readiness->suggested === StatusCategory::Started
                    && $workflow->auto_start
                    && ($to = $workflow->statusFor(StatusCategory::Started))) {
                    $actions->setStatus($locked, $to, $actor, 'auto');
                    $category = $locked->status_category;
                }

                if ($readiness->ready && $workflow->auto_complete && ! $category->isTerminal() && ($to = $workflow->statusFor(StatusCategory::Completed))) {
                    $actions->setStatus($locked, $to, $actor, 'auto');
                } elseif ($readiness->ready && ! $wasReady) {
                    $actions->record($locked, IssueActivityType::CompletionReady, $actor, ['readiness' => $readiness->toArray()]);
                    $actions->notifyReadyToComplete($locked, $readiness);
                }
            }

            $issue->setRawAttributes($locked->getAttributes(), true);
            $issue->unsetRelation('status');

            IssueCompletionRecomputed::dispatch($locked, $readiness->ready);

            return $readiness;
        });
    }
}
