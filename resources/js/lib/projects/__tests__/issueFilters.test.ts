import { describe, expect, it } from 'vitest';
import {
    clauseCount,
    fromQuery,
    matches,
    normalizeFilters,
    toQuery,
    withClause,
    withoutClause,
} from '@/lib/projects/issueFilters';
import type { IssueFilters, IssueListItem, WorkflowStatus } from '@/types';

const status: WorkflowStatus = {
    id: 3,
    uuid: 's',
    workflow_id: 1,
    name: 'In Progress',
    color: 'amber',
    icon: null,
    category: 'started',
    position: 2,
    is_default: false,
};

const issue = (overrides: Partial<IssueListItem> = {}): IssueListItem => ({
    uuid: 'u1',
    identifier: 'FRONT-1',
    number: 1,
    title: 'Redesign reporting',
    status,
    status_category: 'started',
    is_ready_to_complete: false,
    suggested_status_id: null,
    priority: 'high',
    owner: {
        id: 7,
        name: 'Jamie',
        email: 'j@x.test',
        initials: 'J',
        avatar: null,
    },
    lead_team: null,
    assignees: [
        {
            id: 9,
            name: 'Priya',
            email: 'p@x.test',
            initials: 'P',
            avatar: null,
        },
    ],
    teams: [
        {
            id: 2,
            slug: 'eng',
            name: 'Engineering',
            color: 'teal',
            icon: null,
            status_category: 'started',
            status_color: 'amber',
            status_name: 'In Progress',
            blocked: false,
        },
    ],
    workstream: null,
    product: {
        id: 4,
        uuid: 'p',
        slug: 'frontier',
        name: 'Frontier',
        key: 'FRONT',
        color: 'violet',
        icon: null,
        status: 'active',
        issues_count: 1,
        open_issues_count: 1,
    },
    project: null,
    modules: [{ id: 5, slug: 'reporting', name: 'Reporting', color: null }],
    milestone: null,
    labels: [{ id: 6, name: 'bug', slug: 'bug', color: 'rose' }],
    estimate: 3,
    start_date: null,
    due_date: '2026-11-10',
    parent: null,
    counts: {
        sub_issues: 0,
        sub_completed: 0,
        comments: 0,
        attachments: 0,
        links: 0,
        workstreams: 1,
        blocked_by: 1,
        blocked_workstreams: 0,
    },
    sort_order: null,
    started_at: null,
    completed_at: null,
    created_at: '2026-11-01T10:00:00Z',
    updated_at: '2026-11-05T10:00:00Z',
    last_activity_at: null,
    ...overrides,
});

const filters = (
    clauses: IssueFilters['groups'][number]['clauses'],
    match: 'all' | 'any' = 'all',
): IssueFilters => ({ q: null, match, groups: [{ match, clauses }] });

describe('issueFilters', () => {
    it('round-trips filters and display through the query string', () => {
        const input = filters([
            { field: 'status', op: 'in', value: [3, 4] },
            { field: 'due_date', op: 'overdue', value: null },
        ]);
        const qs = toQuery(
            { ...input, q: 'report' },
            {
                mode: 'board',
                group_by: 'priority',
                sort: { field: 'due_date', dir: 'asc' },
                show_completed: 'none',
            },
        );
        const parsed = fromQuery(qs);

        expect(parsed.filters.q).toBe('report');
        expect(parsed.filters.groups[0].clauses).toHaveLength(2);
        expect(parsed.filters.groups[0].clauses[0]).toEqual({
            field: 'status',
            op: 'in',
            value: [3, 4],
        });
        expect(parsed.display).toEqual({
            mode: 'board',
            group_by: 'priority',
            sort: { field: 'due_date', dir: 'asc' },
            show_completed: 'none',
        });
    });

    it('drops empty groups and defaults match to all', () => {
        const normalized = normalizeFilters({
            match: 'weird' as never,
            groups: [
                { match: 'any', clauses: [] },
                {
                    match: 'all',
                    clauses: [{ field: 'priority', op: 'in', value: ['high'] }],
                },
            ],
        });

        expect(normalized.match).toBe('all');
        expect(normalized.groups).toHaveLength(1);
        expect(clauseCount(normalized)).toBe(1);
    });

    it('matches list operators including "me" and exclusion', () => {
        const i = issue();

        expect(
            matches(i, filters([{ field: 'status', op: 'in', value: [3] }]), 7),
        ).toBe(true);
        expect(
            matches(
                i,
                filters([{ field: 'status', op: 'not_in', value: [3] }]),
                7,
            ),
        ).toBe(false);
        expect(
            matches(
                i,
                filters([{ field: 'owner', op: 'in', value: ['me'] }]),
                7,
            ),
        ).toBe(true);
        expect(
            matches(
                i,
                filters([{ field: 'assignee', op: 'in', value: ['me'] }]),
                7,
            ),
        ).toBe(false);
        expect(
            matches(i, filters([{ field: 'label', op: 'in', value: [6] }]), 7),
        ).toBe(true);
        expect(
            matches(
                i,
                filters([{ field: 'project', op: 'is_empty', value: null }]),
                7,
            ),
        ).toBe(true);
        expect(
            matches(
                i,
                filters([{ field: 'module', op: 'is_not_empty', value: null }]),
                7,
            ),
        ).toBe(true);
    });

    it('matches boolean, date, number and text operators', () => {
        const i = issue();

        expect(
            matches(
                i,
                filters([{ field: 'blocked', op: 'is_true', value: null }]),
                7,
            ),
        ).toBe(true);
        expect(
            matches(
                i,
                filters([
                    { field: 'has_sub_issues', op: 'is_false', value: null },
                ]),
                7,
            ),
        ).toBe(true);
        expect(
            matches(
                i,
                filters([
                    { field: 'due_date', op: 'before', value: '2026-11-30' },
                ]),
                7,
            ),
        ).toBe(true);
        expect(
            matches(
                i,
                filters([
                    { field: 'due_date', op: 'after', value: '2026-11-30' },
                ]),
                7,
            ),
        ).toBe(false);
        expect(
            matches(
                i,
                filters([
                    {
                        field: 'due_date',
                        op: 'between',
                        value: ['2026-11-01', '2026-11-30'],
                    },
                ]),
                7,
            ),
        ).toBe(true);
        expect(
            matches(i, filters([{ field: 'estimate', op: 'gt', value: 2 }]), 7),
        ).toBe(true);
        expect(
            matches(i, filters([{ field: 'estimate', op: 'lt', value: 2 }]), 7),
        ).toBe(false);
        expect(
            matches(
                i,
                filters([{ field: 'title', op: 'contains', value: 'REPORT' }]),
                7,
            ),
        ).toBe(true);
        expect(
            matches(
                i,
                filters([
                    { field: 'title', op: 'not_contains', value: 'report' },
                ]),
                7,
            ),
        ).toBe(false);
    });

    it('combines groups with all/any', () => {
        const i = issue();
        const both: IssueFilters = {
            q: null,
            match: 'any',
            groups: [
                {
                    match: 'all',
                    clauses: [{ field: 'priority', op: 'in', value: ['low'] }],
                },
                {
                    match: 'all',
                    clauses: [
                        {
                            field: 'status_category',
                            op: 'in',
                            value: ['started'],
                        },
                    ],
                },
            ],
        };

        expect(matches(i, both, 7)).toBe(true);
        expect(matches(i, { ...both, match: 'all' }, 7)).toBe(false);
        expect(
            matches(
                i,
                filters(
                    [
                        { field: 'priority', op: 'in', value: ['low'] },
                        {
                            field: 'status_category',
                            op: 'in',
                            value: ['started'],
                        },
                    ],
                    'any',
                ),
                7,
            ),
        ).toBe(true);
    });

    it('adds and removes clauses', () => {
        const added = withClause(
            { q: null, match: 'all', groups: [] },
            { field: 'priority', op: 'in', value: ['high'] },
        );
        expect(added.groups[0].clauses).toHaveLength(1);

        const replaced = withClause(added, {
            field: 'priority',
            op: 'in',
            value: ['low'],
        });
        expect(replaced.groups[0].clauses).toHaveLength(1);
        expect(replaced.groups[0].clauses[0].value).toEqual(['low']);

        const removed = withoutClause(replaced, 0, 0);
        expect(removed.groups).toHaveLength(0);
    });
});
