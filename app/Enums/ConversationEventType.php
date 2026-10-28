<?php

namespace App\Enums;

enum ConversationEventType: string
{
    case MessageReceived = 'message_received';
    case MessageSent = 'message_sent';
    case MessageFailed = 'message_failed';
    case CommentAdded = 'comment_added';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case TagAdded = 'tag_added';
    case TagRemoved = 'tag_removed';
    case Moved = 'moved';
    case Shared = 'shared';
    case Unshared = 'unshared';
    case Snoozed = 'snoozed';
    case Unsnoozed = 'unsnoozed';
    case Archived = 'archived';
    case Trashed = 'trashed';
    case Restored = 'restored';
    case FollowerAdded = 'follower_added';
    case FollowerRemoved = 'follower_removed';
    case AutomationRan = 'automation_ran';
}
