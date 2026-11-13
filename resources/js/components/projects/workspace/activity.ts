import type { Activity } from '@/types';

type P = Record<string, unknown>;

const s = (p: P, key: string): string =>
    p[key] === null || p[key] === undefined ? '' : String(p[key]);

/** A short sentence for an activity row, without the actor (rendered separately). */
export function describeActivity(activity: Activity): string {
    const p = activity.payload ?? {};
    const team = activity.team?.name ?? s(p, 'team');

    switch (activity.type) {
        case 'created':
            return 'created the issue';
        case 'updated': {
            const changes = (p.changes as P | undefined) ?? {};
            const keys = Object.keys(changes);

            return keys.length > 0
                ? `updated ${keys.join(', ')}`
                : 'updated the issue';
        }
        case 'status_changed':
            return s(p, 'source') === 'auto'
                ? `status moved to ${s(p, 'to')} automatically`
                : `changed status from ${s(p, 'from')} to ${s(p, 'to')}`;
        case 'status_overridden':
            return `completed the issue with an override${s(p, 'reason') ? ` — ${s(p, 'reason')}` : ''}`;
        case 'completion_ready':
            return 'every required team finished; ready to complete';
        case 'completion_conflict':
            return 'a team reopened work on a completed issue';
        case 'priority_changed':
            return `set priority to ${s(p, 'to')}`;
        case 'owner_changed':
            return s(p, 'to_name')
                ? `made ${s(p, 'to_name')} the owner`
                : 'removed the owner';
        case 'lead_team_changed':
            return s(p, 'to')
                ? `set ${s(p, 'to')} as lead team`
                : 'removed the lead team';
        case 'team_added':
            return `added ${team}`;
        case 'team_removed':
            return `removed ${team}`;
        case 'workstream_updated':
            return `updated the ${team} workstream`;
        case 'workstream_status_changed':
            return `${team}: ${s(p, 'from')} → ${s(p, 'to')}`;
        case 'workstream_status_overridden':
            return `${team}: completed while still blocked`;
        case 'workstream_assigned':
            return `assigned ${s(p, 'user')} for ${team}`;
        case 'workstream_unassigned':
            return `unassigned ${s(p, 'user')} from ${team}`;
        case 'workstream_cycle_changed':
            return `${team}: moved to ${s(p, 'to') || 'no cycle'}`;
        case 'cycle_carried_over':
            return `${team}: carried over to ${s(p, 'to')}`;
        case 'workstream_required_changed':
            return `${team} is now ${p.is_required ? 'required' : 'optional'}`;
        case 'label_added':
            return `added label ${s(p, 'label')}`;
        case 'label_removed':
            return `removed label ${s(p, 'label')}`;
        case 'modules_changed':
            return 'changed modules';
        case 'moved':
            return `moved to ${s(p, 'to_project') || s(p, 'to_product') || 'the organization'}`;
        case 'parent_changed':
            return s(p, 'to_identifier')
                ? `made this a sub-issue of ${s(p, 'to_identifier')}`
                : 'detached from its parent';
        case 'milestone_changed':
            return s(p, 'to')
                ? `set milestone to ${s(p, 'to')}`
                : 'removed the milestone';
        case 'dates_changed':
            return 'changed dates';
        case 'estimate_changed':
            return `set estimate to ${s(p, 'to') || 'none'}`;
        case 'relation_added':
            return `linked ${s(p, 'issue')} ${s(p, 'type')} ${s(p, 'related')}`;
        case 'relation_removed':
            return `removed the ${s(p, 'type')} link with ${s(p, 'related')}`;
        case 'dependency_added':
            return `${team} now waits for ${s(p, 'depends_on')}`;
        case 'dependency_removed':
            return `${team} no longer waits for ${s(p, 'depends_on')}`;
        case 'dependency_resolved':
            return `${team ? team + ' is ' : ''}unblocked: ${s(p, 'resolved_by')} is done`;
        case 'dependency_reopened':
            return `blocked again by ${s(p, 'reopened_by')}`;
        case 'handoff':
            return `${s(p, 'from_team')} handed over to ${s(p, 'to_team')}`;
        case 'comment_added':
            return 'commented';
        case 'attachment_added':
            return `attached ${s(p, 'filename')}`;
        case 'attachment_removed':
            return `removed ${s(p, 'filename')}`;
        case 'conversation_linked':
            return 'linked an email conversation';
        case 'conversation_unlinked':
            return 'unlinked an email conversation';
        case 'content_shared':
            return `shared an email ${s(p, 'kind')} into the issue`;
        case 'follower_added':
            return `${s(p, 'user')} started following`;
        case 'follower_removed':
            return `${s(p, 'user')} stopped following`;
        case 'automation_ran':
            return `automation “${s(p, 'rule_name')}” ran`;
        case 'deleted':
            return 'deleted the issue';
        case 'restored':
            return 'restored the issue';
        default:
            return activity.type.replace(/_/g, ' ');
    }
}
