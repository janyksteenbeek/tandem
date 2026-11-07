import { describe, expect, it } from 'vitest';
import {
    flattenGroups,
    groupIssues,
    sortIssues,
} from '@/lib/projects/issueGrouping';
import type { IssueListItem, WorkflowStatus } from '@/types';

const status = (
    id: number,
    name: string,
    category: WorkflowStatus['category'],
    position: number,
): WorkflowStatus => ({
    id,
    uuid: `s${id}`,
    workflow_id: 1,
    name,
    color: 'sky',
    icon: null,
    category,
    position,
    is_default: false,
});
const todo = status(1, 'Todo', 'unstarted', 1);
const doing = status(2, 'Doing', 'started', 2);
const done = status(3, 'Done', 'completed', 3);

const issue = (
    n: number,
    overrides: Partial<IssueListItem> = {},
): IssueListItem => ({
    uuid: `u${n}`,
    identifier: `T-${n}`,
    number: n,
    title: `Issue ${n}`,
    status: todo,
    status_category: 'unstarted',
    is_ready_to_complete: false,
    suggested_status_id: null,
    priority: 'none',
    owner: null,
    lead_team: null,
    assignees: [],
    teams: [],
    workstream: null,
    product: null,
    project: null,
    modules: [],
    milestone: null,
    labels: [],
    estimate: null,
    start_date: null,
    due_date: null,
    parent: null,
    counts: {
        sub_issues: 0,
        sub_completed: 0,
        comments: 0,
        attachments: 0,
        links: 0,
        workstreams: 0,
        blocked_by: 0,
        blocked_workstreams: 0,
    },
    sort_order: null,
    started_at: null,
    completed_at: null,
    created_at: `2026-11-0${n}T00:00:00Z`,
    updated_at: `2026-11-0${n}T00:00:00Z`,
    last_activity_at: null,
    ...overrides,
});

describe('issueGrouping', () => {
    it('groups by status in workflow order', () => {
        const groups = groupIssues(
            [
                issue(1, { status: done, status_category: 'completed' }),
                issue(2, { status: doing, status_category: 'started' }),
                issue(3),
            ],
            'status',
            null,
            [todo, doing, done],
        );

        expect(groups.map((g) => g.label)).toEqual(['Todo', 'Doing', 'Done']);
        expect(groups[0].issues.map((i) => i.number)).toEqual([3]);
    });

    it('puts "No X" groups last and sorts priorities urgent first', () => {
        const byProject = groupIssues(
            [
                issue(1),
                issue(2, {
                    project: {
                        id: 9,
                        uuid: 'p',
                        slug: 'p',
                        name: 'Alpha',
                        color: 'teal',
                        icon: null,
                        status: 'planned',
                        health: 'unknown',
                        priority: 0,
                        visibility: 'organization',
                        start_date: null,
                        target_date: null,
                        completed_at: null,
                        lead: null,
                        product: null,
                        teams: [],
                        progress: 0,
                        counts: {
                            issues: 0,
                            completed: 0,
                            started: 0,
                            canceled: 0,
                        },
                    },
                }),
            ],
            'project',
            null,
        );
        expect(byProject.map((g) => g.label)).toEqual(['Alpha', 'No project']);

        const byPriority = groupIssues(
            [
                issue(1, { priority: 'low' }),
                issue(2, { priority: 'urgent' }),
                issue(3),
            ],
            'priority',
            null,
        );
        expect(byPriority.map((g) => g.label)).toEqual([
            'Urgent',
            'Low',
            'No priority',
        ]);
    });

    it('uses the team workstream status on team pages', () => {
        const ws = {
            id: 1,
            uuid: 'w',
            issue_id: 1,
            team: { id: 5, slug: 't', name: 'QA', color: 'rose', icon: null },
            responsibility: null,
            status: doing,
            assignees: [],
            estimate: null,
            due_date: null,
            is_required: true,
            cycle: null,
            blocked_by_count: 0,
            position: 0,
            started_at: null,
            completed_at: null,
            updated_at: null,
        };
        const groups = groupIssues(
            [issue(1, { workstream: ws })],
            'status',
            null,
            [todo, doing, done],
            5,
        );

        expect(groups[0].label).toBe('Doing');
    });

    it('sorts by priority, due date with nulls last, and identifier', () => {
        const list = [
            issue(1, { priority: 'low', due_date: null }),
            issue(2, { priority: 'urgent', due_date: '2026-11-02' }),
            issue(3, { priority: 'high', due_date: '2026-11-01' }),
        ];

        expect(
            sortIssues(list, { field: 'priority', dir: 'desc' }).map(
                (i) => i.number,
            ),
        ).toEqual([2, 3, 1]);
        expect(
            sortIssues(list, { field: 'due_date', dir: 'asc' }).map(
                (i) => i.number,
            ),
        ).toEqual([3, 2, 1]);
        expect(
            sortIssues(list, { field: 'identifier', dir: 'asc' }).map(
                (i) => i.number,
            ),
        ).toEqual([1, 2, 3]);
    });

    it('flattens groups and skips collapsed ones', () => {
        const groups = groupIssues(
            [issue(1), issue(2, { status: doing, status_category: 'started' })],
            'status',
            null,
            [todo, doing],
        );
        const rows = flattenGroups(groups, new Set(['2']));

        expect(rows.map((r) => r.kind)).toEqual(['group', 'issue', 'group']);
        expect(flattenGroups(groups, new Set(), false)).toHaveLength(2);
    });
});
