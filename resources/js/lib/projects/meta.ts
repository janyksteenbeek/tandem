import type {
    IssuePriority,
    ProjectHealth,
    ProjectStatus,
    StatusCategory,
} from '@/types';

export const PRIORITY_ORDER: IssuePriority[] = [
    'urgent',
    'high',
    'medium',
    'low',
    'none',
];

export const PRIORITY_META: Record<
    IssuePriority,
    { label: string; short: string; rank: number; className: string }
> = {
    none: {
        label: 'No priority',
        short: '—',
        rank: 0,
        className: 'text-muted-foreground/70',
    },
    low: {
        label: 'Low',
        short: 'Low',
        rank: 1,
        className: 'text-muted-foreground',
    },
    medium: {
        label: 'Medium',
        short: 'Med',
        rank: 2,
        className: 'text-foreground',
    },
    high: { label: 'High', short: 'High', rank: 3, className: 'text-warning' },
    urgent: {
        label: 'Urgent',
        short: 'Urgent',
        rank: 4,
        className: 'text-destructive',
    },
};

export const CATEGORY_ORDER: StatusCategory[] = [
    'backlog',
    'unstarted',
    'started',
    'completed',
    'canceled',
];

export const CATEGORY_META: Record<
    StatusCategory,
    { label: string; token: string; terminal: boolean }
> = {
    backlog: { label: 'Backlog', token: 'status-backlog', terminal: false },
    unstarted: { label: 'Todo', token: 'status-unstarted', terminal: false },
    started: { label: 'In progress', token: 'status-started', terminal: false },
    completed: { label: 'Done', token: 'status-completed', terminal: true },
    canceled: { label: 'Canceled', token: 'status-canceled', terminal: true },
};

export const PROJECT_STATUS_META: Record<
    ProjectStatus,
    { label: string; dot: string }
> = {
    backlog: { label: 'Backlog', dot: 'bg-muted-foreground/50' },
    planned: { label: 'Planned', dot: 'bg-info' },
    in_progress: { label: 'In progress', dot: 'bg-warning' },
    paused: { label: 'Paused', dot: 'bg-muted-foreground' },
    completed: { label: 'Completed', dot: 'bg-brand' },
    canceled: { label: 'Canceled', dot: 'bg-destructive/70' },
};

export const PROJECT_HEALTH_META: Record<
    ProjectHealth,
    { label: string; className: string }
> = {
    unknown: { label: 'No update', className: 'text-muted-foreground' },
    on_track: { label: 'On track', className: 'text-success' },
    at_risk: { label: 'At risk', className: 'text-warning' },
    off_track: { label: 'Off track', className: 'text-destructive' },
};

export const ESTIMATE_SCALES: Record<string, number[]> = {
    none: [],
    fibonacci: [1, 2, 3, 5, 8, 13, 21],
    linear: [1, 2, 3, 4, 5, 6, 7, 8],
    tshirt: [1, 2, 3, 5, 8],
};

export const TSHIRT_LABELS: Record<number, string> = {
    1: 'XS',
    2: 'S',
    3: 'M',
    5: 'L',
    8: 'XL',
};

export function formatEstimate(
    value: number | null | undefined,
    scale = 'fibonacci',
): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (scale === 'tshirt') {
        return TSHIRT_LABELS[value] ?? String(value);
    }

    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

export const IDENTIFIER_PATTERN = /^[A-Z][A-Z0-9]{1,9}-\d+$/i;

export function issueUrl(orgSlug: string, identifier: string): string {
    return `/${orgSlug}/issues/${identifier}`;
}

export const EMPTY_COPY: Record<string, { title: string; body: string }> = {
    default: {
        title: 'Nothing here yet.',
        body: 'Create an issue to get the ball rolling.',
    },
    assigned: {
        title: 'Nothing assigned to you.',
        body: 'Pick something from a backlog, or enjoy the quiet.',
    },
    owned: {
        title: 'You own nothing. Freeing, isn’t it?',
        body: 'Issues you are accountable for will show up here.',
    },
    workstreams: {
        title: 'No open work for your teams.',
        body: 'When your team joins an issue, it lands here.',
    },
    created: {
        title: 'You haven’t created anything yet.',
        body: 'Press C anywhere to create an issue.',
    },
    subscribed: {
        title: 'Not following anything.',
        body: 'Follow an issue to get its updates here.',
    },
    due_soon: { title: 'Nothing due this week.', body: 'Breathe.' },
    overdue: {
        title: 'Nothing overdue.',
        body: 'Everything is on time. Keep it that way.',
    },
    completed: {
        title: 'Nothing completed yet.',
        body: 'Finished issues collect here.',
    },
    recent: {
        title: 'No recent issues.',
        body: 'Issues you open will be listed here for quick access.',
    },
    search: {
        title: 'No matches.',
        body: 'Try an identifier like FRONT-12, or fewer words.',
    },
    board: {
        title: 'Empty column.',
        body: 'Drag an issue here or create one.',
    },
    product: {
        title: 'No issues in this product.',
        body: 'Create the first one, or link one from a conversation.',
    },
    project: {
        title: 'No issues in this project.',
        body: 'Add issues to start tracking progress.',
    },
    team: {
        title: 'This team has no work yet.',
        body: 'Add the team to an issue and it will show up here.',
    },
};
