import type { TimelineRow } from '@/lib/projects/timelineScale';
import type {
    IssueListItem,
    Milestone,
    ProjectSummary,
    Release,
} from '@/types';

export type Dependency = { from: string; to: string };

type ProjectLike = Pick<
    ProjectSummary,
    | 'uuid'
    | 'name'
    | 'color'
    | 'start_date'
    | 'target_date'
    | 'progress'
    | 'slug'
> & { milestones?: Milestone[] };

/**
 * Rows for a list of issues: optional project row on top, its milestones as diamonds, then the
 * issues nested under their parents. Issues without a start fall back to their creation day
 * when they have a due date, so a bar can always be drawn for dated work.
 */
export function issueRows(
    issues: IssueListItem[],
    options: {
        project?: ProjectLike | null;
        milestones?: Milestone[] | null;
        dependencies?: Dependency[];
        orgSlug?: string;
    } = {},
): TimelineRow[] {
    const dependsOn = new Map<string, string[]>();

    for (const dep of options.dependencies ?? []) {
        dependsOn.set(`issue:${dep.to}`, [
            ...(dependsOn.get(`issue:${dep.to}`) ?? []),
            `issue:${dep.from}`,
        ]);
    }

    const byUuid = new Map(issues.map((issue) => [issue.uuid, issue]));
    const children = new Map<string, TimelineRow[]>();

    const toRow = (issue: IssueListItem): TimelineRow => {
        const start =
            issue.start_date ??
            (issue.due_date ? (issue.created_at?.slice(0, 10) ?? null) : null);
        const end =
            issue.due_date ?? (issue.start_date ? issue.start_date : null);

        return {
            id: `issue:${issue.uuid}`,
            kind: 'issue',
            label: issue.title,
            identifier: issue.identifier,
            start,
            end: end && start && end < start ? start : end,
            progress:
                issue.status_category === 'completed'
                    ? 1
                    : issue.counts.sub_issues > 0
                      ? issue.counts.sub_completed / issue.counts.sub_issues
                      : issue.status_category === 'started'
                        ? 0.4
                        : 0,
            parentId:
                issue.parent && byUuid.has(issue.parent.uuid)
                    ? `issue:${issue.parent.uuid}`
                    : null,
            color:
                issue.project?.color ??
                issue.product?.color ??
                issue.teams[0]?.color ??
                null,
            dependsOn: dependsOn.get(`issue:${issue.uuid}`) ?? [],
            url: options.orgSlug
                ? `/${options.orgSlug}/issues/${issue.identifier}`
                : undefined,
            status: issue.status,
            blocked:
                issue.counts.blocked_by > 0 ||
                issue.counts.blocked_workstreams > 0,
            meta: { uuid: issue.uuid, identifier: issue.identifier },
        };
    };

    const rows = issues.map(toRow);
    const roots: TimelineRow[] = [];

    for (const row of rows) {
        if (row.parentId) {
            children.set(row.parentId, [
                ...(children.get(row.parentId) ?? []),
                row,
            ]);
        } else {
            roots.push(row);
        }
    }

    const attach = (row: TimelineRow): TimelineRow => ({
        ...row,
        children: (children.get(row.id) ?? []).map(attach),
    });
    const issueTree = roots.map(attach);

    if (!options.project) {
        return issueTree;
    }

    const project = options.project;
    const milestones = options.milestones ?? project.milestones ?? [];

    const projectRow: TimelineRow = {
        id: `project:${project.uuid}`,
        kind: 'project',
        label: project.name,
        start: project.start_date,
        end: project.target_date,
        progress: project.progress,
        parentId: null,
        color: project.color,
        dependsOn: [],
        url: options.orgSlug
            ? `/${options.orgSlug}/projects/${project.slug}`
            : undefined,
        meta: { slug: project.slug },
        children: [
            ...milestones.map((milestone): TimelineRow => ({
                id: `milestone:${milestone.uuid}`,
                kind: 'milestone',
                label: milestone.name,
                start: milestone.target_date,
                end: milestone.target_date,
                progress: milestone.progress,
                parentId: `project:${project.uuid}`,
                color: project.color,
                dependsOn: [],
                meta: { uuid: milestone.uuid, project_slug: project.slug },
            })),
            ...issueTree.map((row) => ({
                ...row,
                parentId: `project:${project.uuid}`,
            })),
        ],
    };

    return [projectRow];
}

/** Product roadmap: every project as a bar, every release as a diamond. */
export function productRoadmapRows(
    projects: ProjectSummary[],
    releases: Release[],
    orgSlug?: string,
    productSlug?: string,
): TimelineRow[] {
    const projectRows = projects.map((project): TimelineRow => ({
        id: `project:${project.uuid}`,
        kind: 'project',
        label: project.name,
        start: project.start_date,
        end: project.target_date,
        progress: project.progress,
        parentId: null,
        color: project.color,
        dependsOn: [],
        url: orgSlug ? `/${orgSlug}/projects/${project.slug}` : undefined,
        meta: { slug: project.slug, status: project.status },
    }));

    const releaseRows = releases.map((release): TimelineRow => ({
        id: `release:${release.uuid}`,
        kind: 'release',
        label: release.version
            ? `${release.name} ${release.version}`
            : release.name,
        start: release.planned_at,
        end: release.planned_at,
        progress:
            release.issues_count > 0
                ? release.completed_issues_count / release.issues_count
                : 0,
        parentId: null,
        color: 'emerald',
        dependsOn: [],
        url:
            orgSlug && productSlug
                ? `/${orgSlug}/products/${productSlug}/releases/${release.uuid}`
                : undefined,
        meta: { uuid: release.uuid, status: release.status },
    }));

    const dated = (row: TimelineRow) => row.start ?? row.end ?? '9999';

    return [...projectRows, ...releaseRows].sort((a, b) =>
        dated(a).localeCompare(dated(b)),
    );
}

/** Flatten a tree into visible rows, honouring a collapsed set; depth is attached for indentation. */
export function flattenRows(
    rows: TimelineRow[],
    collapsed: Set<string>,
): (TimelineRow & { depth: number; hasChildren: boolean })[] {
    const out: (TimelineRow & { depth: number; hasChildren: boolean })[] = [];

    const walk = (list: TimelineRow[], depth: number) => {
        for (const row of list) {
            const hasChildren = Boolean(
                row.children && row.children.length > 0,
            );
            out.push({ ...row, depth, hasChildren });

            if (hasChildren && !collapsed.has(row.id)) {
                walk(row.children ?? [], depth + 1);
            }
        }
    };

    walk(rows, 0);

    return out;
}

/** Every descendant id of a row (for shift+drag of a subtree). */
export function descendantIds(row: TimelineRow): string[] {
    const ids: string[] = [];

    const walk = (list: TimelineRow[]) => {
        for (const child of list) {
            ids.push(child.id);
            walk(child.children ?? []);
        }
    };

    walk(row.children ?? []);

    return ids;
}
