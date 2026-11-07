import {
    dayDiff,
    parseISO,
    shiftDate,
    startOfMonth,
    startOfWeek,
    todayISO,
} from '@/lib/projects/calendarLayout';
import type { WorkflowStatus } from '@/types';

export type TimelineZoom = 'day' | 'week' | 'month' | 'quarter';

/** Pixels per day. */
export const ZOOMS: Record<TimelineZoom, number> = {
    day: 44,
    week: 18,
    month: 6,
    quarter: 2.4,
};

export const ZOOM_ORDER: TimelineZoom[] = ['quarter', 'month', 'week', 'day'];

export type TimelineRowKind =
    | 'project'
    | 'milestone'
    | 'issue'
    | 'workstream'
    | 'release'
    | 'cycle';

export type TimelineRow = {
    id: string;
    kind: TimelineRowKind;
    label: string;
    identifier?: string;
    start: string | null;
    end: string | null;
    /** 0..1 */
    progress: number;
    parentId: string | null;
    color: string | null;
    dependsOn: string[];
    url?: string;
    status?: WorkflowStatus | null;
    blocked?: boolean;
    collapsed?: boolean;
    children?: TimelineRow[];
    /** Anything the owner wants back on shift/open. */
    meta?: Record<string, unknown>;
};

export type ArrowEdge = {
    id: string;
    fromX: number;
    fromY: number;
    toX: number;
    toY: number;
    late: boolean;
};

export type TimelineTick = {
    x: number;
    label: string;
    major: boolean;
    date: string;
};

export type TimelineScale = {
    from: string;
    to: string;
    zoom: TimelineZoom;
    dayWidth: number;
    totalWidth: number;
    days: number;
    xForDate: (iso: string) => number;
    dateForX: (px: number) => string;
    ticks: TimelineTick[];
    /** Thin background lines (every day/week). */
    gridStep: number;
};

export function buildScale(
    from: string,
    to: string,
    zoom: TimelineZoom,
    weekStartsOn: 0 | 1 = 1,
): TimelineScale {
    const dayWidth = ZOOMS[zoom];
    const days = Math.max(1, dayDiff(from, to) + 1);
    const totalWidth = days * dayWidth;

    const xForDate = (iso: string) => dayDiff(from, iso) * dayWidth;
    const dateForX = (px: number) => shiftDate(from, Math.floor(px / dayWidth));

    const ticks: TimelineTick[] = [];

    if (zoom === 'day') {
        for (let i = 0; i < days; i++) {
            const date = shiftDate(from, i);
            const d = parseISO(date);
            ticks.push({
                x: i * dayWidth,
                date,
                label:
                    d.getDate() === 1
                        ? d.toLocaleDateString(undefined, {
                              month: 'short',
                              day: 'numeric',
                          })
                        : String(d.getDate()),
                major: d.getDate() === 1,
            });
        }
    } else if (zoom === 'week') {
        let cursor = startOfWeek(from, weekStartsOn);

        while (cursor <= to) {
            const d = parseISO(cursor);
            ticks.push({
                x: xForDate(cursor),
                date: cursor,
                label: d.toLocaleDateString(undefined, {
                    month: 'short',
                    day: 'numeric',
                }),
                major: d.getDate() <= 7,
            });
            cursor = shiftDate(cursor, 7);
        }
    } else {
        let cursor = startOfMonth(from);

        while (cursor <= to) {
            const d = parseISO(cursor);
            const quarterStart = d.getMonth() % 3 === 0;
            ticks.push({
                x: xForDate(cursor),
                date: cursor,
                label:
                    zoom === 'quarter' && !quarterStart
                        ? d.toLocaleDateString(undefined, { month: 'narrow' })
                        : d.toLocaleDateString(undefined, {
                              month: 'short',
                              ...(d.getMonth() === 0
                                  ? { year: 'numeric' }
                                  : {}),
                          }),
                major: zoom === 'quarter' ? quarterStart : d.getMonth() === 0,
            });
            const next = parseISO(cursor);
            next.setMonth(next.getMonth() + 1);
            cursor = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-01`;
        }
    }

    return {
        from,
        to,
        zoom,
        dayWidth,
        totalWidth,
        days,
        xForDate,
        dateForX,
        ticks,
        gridStep:
            zoom === 'day' ? dayWidth : zoom === 'week' ? dayWidth * 7 : 0,
    };
}

/**
 * The date window a set of rows needs, padded so bars never touch the edges and "today" is
 * always visible.
 */
export function clampRange(
    rows: TimelineRow[],
    today = todayISO(),
    zoom: TimelineZoom = 'week',
): { from: string; to: string } {
    const bounds: { min: string | null; max: string | null } = {
        min: null,
        max: null,
    };

    const visit = (list: TimelineRow[]) => {
        for (const row of list) {
            for (const date of [row.start, row.end]) {
                if (date) {
                    bounds.min =
                        bounds.min === null || date < bounds.min
                            ? date
                            : bounds.min;
                    bounds.max =
                        bounds.max === null || date > bounds.max
                            ? date
                            : bounds.max;
                }
            }

            if (row.children) {
                visit(row.children);
            }
        }
    };

    visit(rows);

    const pad =
        zoom === 'day' ? 7 : zoom === 'week' ? 14 : zoom === 'month' ? 30 : 60;
    const minimumSpan =
        zoom === 'day'
            ? 30
            : zoom === 'week'
              ? 90
              : zoom === 'month'
                ? 270
                : 540;

    let from = shiftDate(
        bounds.min === null || today < bounds.min ? today : bounds.min,
        -pad,
    );
    let to = shiftDate(
        bounds.max === null || today > bounds.max ? today : bounds.max,
        pad,
    );

    if (dayDiff(from, to) < minimumSpan) {
        to = shiftDate(from, minimumSpan);
    }

    from = startOfWeek(from, 1);

    return { from, to };
}

export function zoomIn(zoom: TimelineZoom): TimelineZoom {
    return ZOOM_ORDER[
        Math.min(ZOOM_ORDER.length - 1, ZOOM_ORDER.indexOf(zoom) + 1)
    ];
}

export function zoomOut(zoom: TimelineZoom): TimelineZoom {
    return ZOOM_ORDER[Math.max(0, ZOOM_ORDER.indexOf(zoom) - 1)];
}

export { dayDiff };
