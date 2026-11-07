import type {
    FilterClause,
    FilterGroup,
    IssueDisplay,
    IssueFilters,
    IssueListItem,
} from '@/types';

export const EMPTY_FILTERS: IssueFilters = {
    q: null,
    match: 'all',
    groups: [],
};

export function normalizeFilters(
    filters: Partial<IssueFilters> | null | undefined,
): IssueFilters {
    return {
        q: filters?.q ?? null,
        match: filters?.match === 'any' ? 'any' : 'all',
        groups: (filters?.groups ?? [])
            .map((group): FilterGroup => ({
                match: group.match === 'any' ? 'any' : 'all',
                clauses: (group.clauses ?? []).filter(Boolean),
            }))
            .filter((group) => group.clauses.length > 0),
    };
}

export function isEmptyFilters(filters: IssueFilters): boolean {
    return !filters.q && filters.groups.length === 0;
}

/** Count of clauses, for badges. */
export function clauseCount(filters: IssueFilters): number {
    return filters.groups.reduce((n, g) => n + g.clauses.length, 0);
}

/**
 * Serialise filters and display options into the query string the controllers read:
 * `filters` as JSON, `q` and the display keys as plain params.
 */
export function toQuery(
    filters: IssueFilters,
    display?: Partial<IssueDisplay> | null,
    extra: Record<string, string | number | null | undefined> = {},
): string {
    const params = new URLSearchParams();
    const normalized = normalizeFilters(filters);

    if (normalized.q) {
        params.set('q', normalized.q);
    }

    if (normalized.groups.length > 0) {
        params.set(
            'filters',
            JSON.stringify({
                match: normalized.match,
                groups: normalized.groups,
            }),
        );
    }

    if (display?.mode) {
        params.set('mode', display.mode);
    }

    if (display?.group_by) {
        params.set('group_by', display.group_by);
    }

    if (display?.sort) {
        params.set('sort', display.sort.field);
        params.set('dir', display.sort.dir);
    }

    if (display?.show_completed) {
        params.set('show_completed', display.show_completed);
    }

    for (const [key, value] of Object.entries(extra)) {
        if (value !== null && value !== undefined && value !== '') {
            params.set(key, String(value));
        }
    }

    const qs = params.toString();

    return qs ? `?${qs}` : '';
}

export function fromQuery(search: string): {
    filters: IssueFilters;
    display: Partial<IssueDisplay>;
} {
    const params = new URLSearchParams(
        search.startsWith('?') ? search.slice(1) : search,
    );
    let filters: IssueFilters = { ...EMPTY_FILTERS, groups: [] };

    const raw = params.get('filters');

    if (raw) {
        try {
            filters = normalizeFilters(JSON.parse(raw));
        } catch {
            filters = { ...EMPTY_FILTERS, groups: [] };
        }
    }

    filters.q = params.get('q') || null;

    const display: Partial<IssueDisplay> = {};
    const mode = params.get('mode');
    const groupBy = params.get('group_by');
    const sort = params.get('sort');
    const showCompleted = params.get('show_completed');

    if (mode) {
        display.mode = mode as IssueDisplay['mode'];
    }

    if (groupBy) {
        display.group_by = groupBy as IssueDisplay['group_by'];
    }

    if (sort) {
        display.sort = {
            field: sort as IssueDisplay['sort']['field'],
            dir: params.get('dir') === 'asc' ? 'asc' : 'desc',
        };
    }

    if (showCompleted) {
        display.show_completed =
            showCompleted as IssueDisplay['show_completed'];
    }

    return { filters, display };
}

function values(value: FilterClause['value']): string[] {
    if (value === null || value === undefined) {
        return [];
    }

    return (Array.isArray(value) ? value : [value]).map(String);
}

function clauseMatches(
    issue: IssueListItem,
    clause: FilterClause,
    meId: number,
): boolean {
    const wanted = values(clause.value).map((v) =>
        v === 'me' ? String(meId) : v,
    );
    const has = (actual: (string | number | null | undefined)[]) => {
        const present = actual
            .filter((a) => a !== null && a !== undefined)
            .map(String);

        switch (clause.op) {
            case 'in':
                return present.some((a) => wanted.includes(a));
            case 'not_in':
                return !present.some((a) => wanted.includes(a));
            case 'is_empty':
                return present.length === 0;
            case 'is_not_empty':
                return present.length > 0;
            default:
                return true;
        }
    };

    const date = (value: string | null) => {
        switch (clause.op) {
            case 'is_empty':
                return value === null;
            case 'is_not_empty':
                return value !== null;
            case 'before':
                return value !== null && value <= wanted[0];
            case 'after':
                return value !== null && value >= wanted[0];
            case 'between':
                return (
                    value !== null && value >= wanted[0] && value <= wanted[1]
                );
            case 'overdue':
                return (
                    value !== null &&
                    value < new Date().toISOString().slice(0, 10) &&
                    !['completed', 'canceled'].includes(issue.status_category)
                );
            default:
                return true;
        }
    };

    switch (clause.field) {
        case 'status':
            return has([issue.status.id]);
        case 'status_category':
            return has([issue.status_category]);
        case 'priority':
            return has([issue.priority]);
        case 'owner':
            return has([issue.owner?.id]);
        case 'assignee':
            return has(issue.assignees.map((a) => a.id));
        case 'team':
            return has(issue.teams.map((t) => t.id));
        case 'lead_team':
            return has([issue.lead_team?.id]);
        case 'product':
            return has([issue.product?.id]);
        case 'module':
            return has(issue.modules.map((m) => m.id));
        case 'project':
            return has([issue.project?.id]);
        case 'milestone':
            return has([issue.milestone?.id]);
        case 'label':
            return has(issue.labels.map((l) => l.id));
        case 'parent':
            return has([issue.parent?.uuid]);
        case 'cycle':
            return has([issue.workstream?.cycle?.id]);
        case 'blocked': {
            const blocked =
                issue.counts.blocked_by > 0 ||
                issue.counts.blocked_workstreams > 0;

            return clause.op === 'is_true' ? blocked : !blocked;
        }
        case 'ready_to_complete':
            return clause.op === 'is_true'
                ? issue.is_ready_to_complete
                : !issue.is_ready_to_complete;
        case 'has_sub_issues':
            return clause.op === 'is_true'
                ? issue.counts.sub_issues > 0
                : issue.counts.sub_issues === 0;
        case 'due_date':
            return date(issue.due_date);
        case 'start_date':
            return date(issue.start_date);
        case 'created_at':
            return date(issue.created_at?.slice(0, 10) ?? null);
        case 'updated_at':
            return date(issue.updated_at?.slice(0, 10) ?? null);
        case 'completed_at':
            return date(issue.completed_at?.slice(0, 10) ?? null);
        case 'estimate': {
            const n = Number(wanted[0]);

            switch (clause.op) {
                case 'eq':
                    return issue.estimate === n;
                case 'gt':
                    return issue.estimate !== null && issue.estimate > n;
                case 'lt':
                    return issue.estimate !== null && issue.estimate < n;
                case 'is_empty':
                    return issue.estimate === null;
                case 'is_not_empty':
                    return issue.estimate !== null;
                default:
                    return true;
            }
        }
        case 'title': {
            const found = issue.title
                .toLowerCase()
                .includes((wanted[0] ?? '').toLowerCase());

            return clause.op === 'contains' ? found : !found;
        }
        default:
            return true;
    }
}

function groupMatches(
    issue: IssueListItem,
    group: FilterGroup,
    meId: number,
): boolean {
    if (group.clauses.length === 0) {
        return true;
    }

    return group.match === 'any'
        ? group.clauses.some((c) => clauseMatches(issue, c, meId))
        : group.clauses.every((c) => clauseMatches(issue, c, meId));
}

/**
 * Client-side membership check so realtime and optimistic updates can decide whether a row
 * belongs in the current list. The search term is ignored (the server decides that).
 */
export function matches(
    issue: IssueListItem,
    filters: IssueFilters,
    meId: number,
): boolean {
    const normalized = normalizeFilters(filters);

    if (normalized.groups.length === 0) {
        return true;
    }

    return normalized.match === 'any'
        ? normalized.groups.some((g) => groupMatches(issue, g, meId))
        : normalized.groups.every((g) => groupMatches(issue, g, meId));
}

export function withClause(
    filters: IssueFilters,
    clause: FilterClause,
): IssueFilters {
    const normalized = normalizeFilters(filters);
    const groups =
        normalized.groups.length > 0
            ? [...normalized.groups]
            : [{ match: 'all' as const, clauses: [] }];
    const first = {
        ...groups[0],
        clauses: [
            ...groups[0].clauses.filter((c) => c.field !== clause.field),
            clause,
        ],
    };
    groups[0] = first;

    return { ...normalized, groups };
}

export function withoutClause(
    filters: IssueFilters,
    groupIndex: number,
    clauseIndex: number,
): IssueFilters {
    const normalized = normalizeFilters(filters);
    const groups = normalized.groups.map((g, gi) =>
        gi === groupIndex
            ? { ...g, clauses: g.clauses.filter((_, ci) => ci !== clauseIndex) }
            : g,
    );

    return normalizeFilters({ ...normalized, groups });
}
