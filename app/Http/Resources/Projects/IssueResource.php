<?php

namespace App\Http\Resources\Projects;

use App\Http\Resources\UserSummaryResource;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueRelation;
use App\Services\Projects\DependencyGraph;
use App\Services\Projects\IssueCompletion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full representation for the detail view.
 *
 * @mixin Issue
 */
class IssueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing([
            'followers', 'createdBy', 'subIssues.status', 'subIssues.workstreams.assignees', 'attachments.uploadedBy',
            'comments.user', 'comments.reactions.user', 'comments.parent', 'relations.relatedIssue.status', 'inverseRelations.issue.status',
            'workstreams.dependencies.dependsOnWorkstream.team', 'workstreams.dependencies.dependsOnWorkstream.issue', 'workstreams.dependencies.dependsOnIssue',
            'sharedContent.attachment', 'links', 'suggestedStatus',
        ]);

        $user = $request->user();
        $readiness = app(IssueCompletion::class)->evaluate($this->resource);

        $relations = $this->relations->map(fn (IssueRelation $r) => $this->relationRow($r, $r->relatedIssue, $r->type->value === 'blocks' ? 'blocks' : $r->type->value))
            ->merge($this->inverseRelations->map(fn (IssueRelation $r) => $this->relationRow($r, $r->issue, $r->type->value === 'blocks' ? 'blocked_by' : $r->type->value)))
            ->values()
            ->all();

        return [
            ...IssueListItemResource::make($this->resource)->resolve(),
            'description_html' => $this->description_html,
            'workstreams' => WorkstreamResource::collection($this->workstreams)->resolve(),
            'workstream_dependencies' => $this->workstreams->flatMap(fn ($w) => app(DependencyGraph::class)->blockersOf($w)->map(fn (array $row) => [
                'id' => $row['id'] ?? null,
                'workstream_uuid' => $w->uuid,
                'team' => TeamSummaryResource::make($w->team)->resolve(),
                'kind' => $row['kind'],
                'issue' => $row['issue'] ? ['uuid' => $row['issue']->uuid, 'identifier' => $row['issue']->identifier, 'title' => $row['issue']->title, 'status_category' => $row['issue']->status_category->value] : null,
                'depends_on_team' => $row['team'] ? TeamSummaryResource::make($row['team'])->resolve() : null,
                'resolved' => $row['resolved'],
            ]))->values()->all(),
            'readiness' => $readiness->toArray(),
            'suggested_status' => $this->suggestedStatus ? WorkflowStatusResource::make($this->suggestedStatus)->resolve() : null,
            'status_overridden_at' => $this->status_overridden_at?->toISOString(),
            'sub_issues' => IssueListItemResource::collection($this->subIssues)->resolve(),
            'relations' => $relations,
            'attachments' => IssueAttachmentResource::collection($this->attachments)->resolve(),
            'comments' => IssueCommentResource::collection($this->comments)->resolve(),
            'shared_content' => $this->sharedContent->map(fn ($content) => [
                'uuid' => $content->uuid,
                'kind' => $content->kind->value,
                'body_html' => $content->body_html,
                'attachment' => $content->attachment ? IssueAttachmentResource::make($content->attachment)->resolve() : null,
                'created_at' => $content->created_at->toISOString(),
            ])->values()->all(),
            'followers' => UserSummaryResource::collection($this->followers)->resolve(),
            'is_following' => $user ? $this->followers->contains('id', $user->id) : false,
            'created_by' => $this->createdBy ? UserSummaryResource::make($this->createdBy)->resolve() : null,
            'links' => $this->links->map(fn ($link) => ['id' => $link->id, 'title' => $link->title, 'url' => $link->url])->values()->all(),
            'link_count' => $this->link_count,
            'canceled_at' => $this->canceled_at?->toISOString(),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'set_status' => $user?->can('setStatus', $this->resource) ?? false,
                'override_completion' => $user?->can('overrideCompletion', $this->resource) ?? false,
                'manage_teams' => $user?->can('addTeam', $this->resource) ?? false,
                'comment' => $user?->can('comment', $this->resource) ?? false,
                'move' => $user?->can('move', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
                'link_conversations' => $user?->can('linkConversation', $this->resource) ?? false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function relationRow(IssueRelation $relation, Issue $other, string $kind): array
    {
        return [
            'id' => $relation->id,
            'kind' => $kind,
            'issue' => [
                'uuid' => $other->uuid,
                'identifier' => $other->identifier,
                'title' => $other->title,
                'status_category' => $other->status_category->value,
                'status' => $other->relationLoaded('status') ? WorkflowStatusResource::make($other->status)->resolve() : null,
            ],
        ];
    }
}
