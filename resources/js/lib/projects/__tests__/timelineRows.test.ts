import { describe, expect, it } from 'vitest';
import {
    descendantIds,
    flattenRows,
    issueRows,
    productRoadmapRows,
} from '@/lib/projects/timelineRows';
import type { IssueListItem, ProjectSummary, Release } from '@/types';

const issue = (overrides: Partial<IssueListItem>): IssueListItem =>
    ({
        uuid: 'u',
        identifier: 'TST-1',
        number: 1,
        title: 'Issue',
        status: {
            id: 1,
            uuid: 's',
            workflow_id: 1,
            name: 'Todo',
            color: 'sky',
            icon: null,
            category: 'unstarted',
            position: 0,
            is_default: true,
        },
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
        created_at: '2026-11-01T10:00:00Z',
        updated_at: null,
        last_activity_at: null,
        ...overrides,
    }) as IssueListItem;

describe('timelineRows', () => {
    it('nests sub-issues under parents and falls back to creation day as a start', () => {
        const rows = issueRows([
            issue({ uuid: 'p', identifier: 'TST-1', due_date: '2026-11-10' }),
            issue({
                uuid: 'c',
                identifier: 'TST-2',
                start_date: '2026-11-03',
                due_date: '2026-11-05',
                parent: { uuid: 'p', identifier: 'TST-1', title: 'Issue' },
            }),
            issue({
                uuid: 'o',
                identifier: 'TST-3',
                parent: { uuid: 'missing', identifier: 'TST-9', title: 'Gone' },
            }),
        ]);

        expect(rows.map((r) => r.id)).toEqual(['issue:p', 'issue:o']);
        expect(rows[0]).toMatchObject({
            start: '2026-11-01',
            end: '2026-11-10',
        });
        expect(rows[0].children?.[0]).toMatchObject({
            id: 'issue:c',
            parentId: 'issue:p',
        });
        expect(rows[1]).toMatchObject({ start: null, end: null });
    });

    it('wraps issues in a project row with milestone diamonds and attaches dependencies', () => {
        const rows = issueRows(
            [
                issue({ uuid: 'a', identifier: 'TST-1' }),
                issue({ uuid: 'b', identifier: 'TST-2' }),
            ],
            {
                project: {
                    uuid: 'pr',
                    slug: 'p',
                    name: 'P',
                    color: 'violet',
                    start_date: '2026-11-01',
                    target_date: '2026-12-01',
                    progress: 0.5,
                },
                milestones: [
                    {
                        id: 1,
                        uuid: 'm1',
                        name: 'Beta',
                        description: null,
                        target_date: '2026-11-15',
                        status: 'planned',
                        position: 0,
                        project_id: 1,
                        initiative_id: null,
                        issues_count: 0,
                        completed_issues_count: 0,
                        progress: 0,
                    },
                ],
                dependencies: [{ from: 'a', to: 'b' }],
            },
        );

        expect(rows).toHaveLength(1);
        expect(rows[0].kind).toBe('project');
        expect(rows[0].children?.map((c) => c.kind)).toEqual([
            'milestone',
            'issue',
            'issue',
        ]);
        expect(rows[0].children?.[2].dependsOn).toEqual(['issue:a']);
    });

    it('builds a roadmap from projects and releases ordered by date', () => {
        const projects = [
            {
                uuid: 'p2',
                slug: 'b',
                name: 'B',
                color: 'teal',
                start_date: '2026-12-01',
                target_date: '2027-01-01',
                progress: 0,
            },
            {
                uuid: 'p1',
                slug: 'a',
                name: 'A',
                color: 'violet',
                start_date: '2026-10-01',
                target_date: '2026-11-01',
                progress: 0,
            },
        ] as ProjectSummary[];
        const releases = [
            {
                uuid: 'r1',
                name: 'Frontier',
                version: '2.0',
                planned_at: '2026-11-15',
                issues_count: 4,
                completed_issues_count: 2,
                status: 'planned',
            },
        ] as Release[];

        const rows = productRoadmapRows(projects, releases, 'org', 'frontier');

        expect(rows.map((r) => r.id)).toEqual([
            'project:p1',
            'release:r1',
            'project:p2',
        ]);
        expect(rows[1]).toMatchObject({
            kind: 'release',
            progress: 0.5,
            url: '/org/products/frontier/releases/r1',
        });
    });

    it('flattens with depth and respects collapsed rows', () => {
        const rows = issueRows([
            issue({ uuid: 'p' }),
            issue({
                uuid: 'c',
                parent: { uuid: 'p', identifier: 'TST-1', title: 'x' },
            }),
        ]);

        expect(
            flattenRows(rows, new Set()).map((r) => [r.id, r.depth]),
        ).toEqual([
            ['issue:p', 0],
            ['issue:c', 1],
        ]);
        expect(
            flattenRows(rows, new Set(['issue:p'])).map((r) => r.id),
        ).toEqual(['issue:p']);
        expect(descendantIds(rows[0])).toEqual(['issue:c']);
    });
});
