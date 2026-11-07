<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\UserSummaryResource;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueWorkstream;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact representation for lists, boards and realtime updates. `assignees` is the union over
 * all workstreams, deduplicated, so one issue is always one row.
 *
 * @mixin Issue
 */
class IssueListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['status', 'owner', 'leadTeam', 'labels', 'project', 'product', 'modules', 'parent', 'milestone', 'workstreams.team', 'workstreams.status', 'workstreams.assignees', 'workstreams.cycle']);

        $assignees = $this->workstreams->flatMap(fn (IssueWorkstream $w) => $w->assignees)->unique('id')->values();
        $focusTeamId = $request->attributes->get('focus_team_id');
        $focus = $focusTeamId ? $this->workstreams->firstWhere('team_id', (int) $focusTeamId) : null;

        return [
            'uuid' => $this->uuid,
            'identifier' => $this->identifier,
            'number' => $this->number,
            'title' => $this->title,
            'status' => WorkflowStatusResource::make($this->status)->resolve(),
            'status_category' => $this->status_category->value,
            'is_ready_to_complete' => $this->is_ready_to_complete,
            'suggested_status_id' => $this->suggested_status_id,
            'priority' => $this->priority->key(),
            'owner' => $this->owner ? UserSummaryResource::make($this->owner)->resolve() : null,
            'lead_team' => $this->leadTeam ? TeamSummaryResource::make($this->leadTeam)->resolve() : null,
            'assignees' => UserSummaryResource::collection($assignees)->resolve(),
            'teams' => $this->workstreams->map(fn (IssueWorkstream $w) => [
                ...TeamSummaryResource::make($w->team)->resolve(),
                'status_category' => $w->status_category->value,
                'status_color' => $w->status->color,
                'status_name' => $w->status->name,
                'blocked' => $w->blocked_by_count > 0,
            ])->values()->all(),
            'workstream' => $focus ? WorkstreamResource::make($focus)->resolve() : null,
            'product' => $this->product ? ProductSummaryResource::make($this->product)->resolve() : null,
            'project' => $this->project ? ProjectSummaryResource::make($this->project)->resolve() : null,
            'modules' => $this->modules->map(fn ($m) => ['id' => $m->id, 'slug' => $m->slug, 'name' => $m->name, 'color' => $m->color])->values()->all(),
            'milestone' => $this->milestone ? ['id' => $this->milestone->id, 'uuid' => $this->milestone->uuid, 'name' => $this->milestone->name] : null,
            'labels' => IssueLabelResource::collection($this->labels)->resolve(),
            'estimate' => $this->estimate !== null ? (float) $this->estimate : null,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'parent' => $this->parent ? ['uuid' => $this->parent->uuid, 'identifier' => $this->parent->identifier, 'title' => $this->parent->title] : null,
            'counts' => [
                'sub_issues' => $this->sub_issues_count,
                'sub_completed' => $this->completed_sub_issues_count,
                'comments' => $this->comment_count,
                'attachments' => $this->attachment_count,
                'links' => $this->link_count,
                'workstreams' => $this->workstreams_count,
                'blocked_by' => $this->blocked_by_count,
                'blocked_workstreams' => $this->blocked_workstreams_count,
            ],
            'sort_order' => $this->sort_order !== null ? (float) $this->sort_order : null,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
        ];
    }
}
