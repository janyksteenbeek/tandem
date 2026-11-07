import {
    CATEGORY_ORDER,
    PRIORITY_META,
    PRIORITY_ORDER,
} from '@/lib/projects/meta';
import type {
    IssueGroupBy,
    IssueListItem,
    IssueLookups,
    IssueSort,
    WorkflowStatus,
} from '@/types';

export type IssueGroup = {
    key: string;
    label: string;
    color?: string | null;
    status?: WorkflowStatus;
    issues: IssueListItem[];
};

export type VirtualRow =
    | { kind: 'group'; group: IssueGroup; index: number }
    | { kind: 'issue'; issue: IssueListItem; groupKey: string; index: number };

const NONE = '__none__';

function compare(
    a: string | number | null,
    b: string | number | null,
    dir: 'asc' | 'desc',
): number {
    if (a === b) {
        return 0;
    }

    if (a === null || a === undefined) {
        return 1;
    }

    if (b === null || b === undefined) {
        return -1;
    }

    const result = a < b ? -1 : 1;

    return dir === 'asc' ? result : -result;
}

export function sortIssues(
    issues: IssueListItem[],
    sort: IssueSort,
    statuses: WorkflowStatus[] = [],
): IssueListItem[] {
    const position = new Map(statuses.map((s) => [s.id, s.position]));
    const key = (issue: IssueListItem): string | number | null => {
        switch (sort.field) {
            case 'priority':
                return PRIORITY_META[issue.priority].rank;
            case 'status':
                return (
                    position.get(issue.status.id) ??
                    CATEGORY_ORDER.indexOf(issue.status_category)
                );
            case 'identifier':
                return issue.number;
            case 'title':
                return issue.title.toLowerCase();
            case 'estimate':
                return issue.estimate;
            case 'due_date':
                return issue.due_date;
            case 'created_at':
                return issue.created_at;
            case 'last_activity_at':
                return issue.last_activity_at;
            case 'manual':
                return issue.sort_order;
            default:
                return issue.updated_at;
        }
    };

    return [...issues].sort(
        (a, b) =>
            compare(key(a), key(b), sort.dir) ||
            compare(a.number, b.number, 'desc'),
    );
}

/**
 * Group issues client-side. Group order is semantic (workflow position, priority rank,
 * alphabetical) with "No X" last.
 */
export function groupIssues(
    issues: IssueListItem[],
    groupBy: IssueGroupBy,
    lookups: Pick<
        IssueLookups,
        | 'members'
        | 'teams'
        | 'projects'
        | 'products'
        | 'modules'
        | 'labels'
        | 'cycles'
        | 'milestones'
    > | null,
    statuses: WorkflowStatus[] = [],
    focusTeamId: number | null = null,
): IssueGroup[] {
    if (groupBy === 'none') {
        return [{ key: 'all', label: 'All issues', issues }];
    }

    const buckets = new Map<string, IssueGroup>();
    const ensure = (
        key: string,
        label: string,
        color?: string | null,
        status?: WorkflowStatus,
    ) => {
        let group = buckets.get(key);

        if (!group) {
            group = { key, label, color, status, issues: [] };
            buckets.set(key, group);
        }

        return group;
    };

    for (const issue of issues) {
        switch (groupBy) {
            case 'status': {
                const status = focusTeamId
                    ? (issue.workstream?.status ?? issue.status)
                    : issue.status;
                ensure(
                    String(status.id),
                    status.name,
                    status.color,
                    status,
                ).issues.push(issue);
                break;
            }
            case 'status_category':
                ensure(
                    issue.status_category,
                    issue.status_category,
                ).issues.push(issue);
                break;
            case 'priority':
                ensure(issue.priority, issue.priority).issues.push(issue);
                break;
            case 'owner':
                ensure(
                    issue.owner ? String(issue.owner.id) : NONE,
                    issue.owner?.name ?? 'No owner',
                ).issues.push(issue);
                break;
            case 'assignee':
                if (issue.assignees.length === 0) {
                    ensure(NONE, 'Unassigned').issues.push(issue);
                }

                for (const assignee of issue.assignees) {
                    ensure(String(assignee.id), assignee.name).issues.push(
                        issue,
                    );
                }
                break;
            case 'team':
                if (issue.teams.length === 0) {
                    ensure(NONE, 'No team').issues.push(issue);
                }

                for (const team of issue.teams) {
                    ensure(String(team.id), team.name, team.color).issues.push(
                        issue,
                    );
                }
                break;
            case 'project':
                ensure(
                    issue.project ? String(issue.project.id) : NONE,
                    issue.project?.name ?? 'No project',
                    issue.project?.color,
                ).issues.push(issue);
                break;
            case 'product':
                ensure(
                    issue.product ? String(issue.product.id) : NONE,
                    issue.product?.name ?? 'No product',
                    issue.product?.color,
                ).issues.push(issue);
                break;
            case 'module':
                if (issue.modules.length === 0) {
                    ensure(NONE, 'No module').issues.push(issue);
                }

                for (const module of issue.modules) {
                    ensure(
                        String(module.id),
                        module.name,
                        module.color,
                    ).issues.push(issue);
                }
                break;
            case 'label':
                if (issue.labels.length === 0) {
                    ensure(NONE, 'No label').issues.push(issue);
                }

                for (const label of issue.labels) {
                    ensure(
                        String(label.id),
                        label.name,
                        label.color,
                    ).issues.push(issue);
                }
                break;
            case 'cycle': {
                const cycle = issue.workstream?.cycle;
                ensure(
                    cycle ? String(cycle.id) : NONE,
                    cycle?.name ?? 'No cycle',
                ).issues.push(issue);
                break;
            }
            case 'milestone':
                ensure(
                    issue.milestone ? String(issue.milestone.id) : NONE,
                    issue.milestone?.name ?? 'No milestone',
                ).issues.push(issue);
                break;
            case 'due_date':
                ensure(
                    dueBucket(issue.due_date),
                    dueBucket(issue.due_date),
                ).issues.push(issue);
                break;
        }
    }

    const groups = [...buckets.values()];

    const order = (group: IssueGroup): number => {
        if (group.key === NONE) {
            return Number.MAX_SAFE_INTEGER;
        }

        switch (groupBy) {
            case 'status':
                return group.status?.position ?? 0;
            case 'status_category':
                return CATEGORY_ORDER.indexOf(group.key as never);
            case 'priority':
                return PRIORITY_ORDER.indexOf(group.key as never);
            case 'due_date':
                return DUE_ORDER.indexOf(group.key);
            default:
                return 0;
        }
    };

    groups.sort(
        (a, b) => order(a) - order(b) || a.label.localeCompare(b.label),
    );

    for (const group of groups) {
        if (groupBy === 'status_category') {
            group.label =
                (
                    {
                        backlog: 'Backlog',
                        unstarted: 'Todo',
                        started: 'In progress',
                        completed: 'Done',
                        canceled: 'Canceled',
                    } as Record<string, string>
                )[group.key] ?? group.label;
        }

        if (groupBy === 'priority') {
            group.label =
                (
                    {
                        urgent: 'Urgent',
                        high: 'High',
                        medium: 'Medium',
                        low: 'Low',
                        none: 'No priority',
                    } as Record<string, string>
                )[group.key] ?? group.label;
        }
    }

    return groups;
}

const DUE_ORDER = ['Overdue', 'Today', 'This week', 'Later', 'No due date'];

function dueBucket(date: string | null): string {
    if (!date) {
        return 'No due date';
    }

    const today = new Date().toISOString().slice(0, 10);

    if (date < today) {
        return 'Overdue';
    }

    if (date === today) {
        return 'Today';
    }

    const week = new Date();
    week.setDate(week.getDate() + 7);

    return date <= week.toISOString().slice(0, 10) ? 'This week' : 'Later';
}

/** Flatten groups into the rows a virtual list renders. */
export function flattenGroups(
    groups: IssueGroup[],
    collapsed: Set<string>,
    showHeaders = true,
): VirtualRow[] {
    const rows: VirtualRow[] = [];
    let index = 0;

    for (const group of groups) {
        if (showHeaders) {
            rows.push({ kind: 'group', group, index: index++ });
        }

        if (collapsed.has(group.key)) {
            continue;
        }

        for (const issue of group.issues) {
            rows.push({
                kind: 'issue',
                issue,
                groupKey: group.key,
                index: index++,
            });
        }
    }

    return rows;
}
