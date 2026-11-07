<?php

namespace App\Enums\Projects;

/**
 * Everything the issue timeline can show. Payload shapes are documented on IssueActions::record.
 */
enum IssueActivityType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case StatusChanged = 'status_changed';
    case StatusOverridden = 'status_overridden';
    case CompletionReady = 'completion_ready';
    case CompletionConflict = 'completion_conflict';
    case PriorityChanged = 'priority_changed';
    case OwnerChanged = 'owner_changed';
    case LeadTeamChanged = 'lead_team_changed';
    case TeamAdded = 'team_added';
    case TeamRemoved = 'team_removed';
    case WorkstreamUpdated = 'workstream_updated';
    case WorkstreamStatusChanged = 'workstream_status_changed';
    case WorkstreamStatusOverridden = 'workstream_status_overridden';
    case WorkstreamAssigned = 'workstream_assigned';
    case WorkstreamUnassigned = 'workstream_unassigned';
    case WorkstreamCycleChanged = 'workstream_cycle_changed';
    case WorkstreamRequiredChanged = 'workstream_required_changed';
    case LabelAdded = 'label_added';
    case LabelRemoved = 'label_removed';
    case ModulesChanged = 'modules_changed';
    case Moved = 'moved';
    case ParentChanged = 'parent_changed';
    case MilestoneChanged = 'milestone_changed';
    case ReleaseChanged = 'release_changed';
    case DatesChanged = 'dates_changed';
    case EstimateChanged = 'estimate_changed';
    case RelationAdded = 'relation_added';
    case RelationRemoved = 'relation_removed';
    case DependencyAdded = 'dependency_added';
    case DependencyRemoved = 'dependency_removed';
    case DependencyResolved = 'dependency_resolved';
    case DependencyReopened = 'dependency_reopened';
    case Handoff = 'handoff';
    case CommentAdded = 'comment_added';
    case AttachmentAdded = 'attachment_added';
    case AttachmentRemoved = 'attachment_removed';
    case ConversationLinked = 'conversation_linked';
    case ConversationUnlinked = 'conversation_unlinked';
    case ContentShared = 'content_shared';
    case FollowerAdded = 'follower_added';
    case FollowerRemoved = 'follower_removed';
    case AutomationRan = 'automation_ran';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case CycleCarriedOver = 'cycle_carried_over';
}
