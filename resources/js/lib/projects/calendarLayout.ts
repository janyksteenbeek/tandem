/**
 * Pure date helpers for the issue calendar. Dates are ISO strings (YYYY-MM-DD) in the user's
 * local calendar; nothing here touches the DOM.
 */
export type CalendarMode = 'month' | 'week';
export type CalendarField = 'due_date' | 'start_date' | 'range';

export type CalendarDay = {
    date: string;
    inMonth: boolean;
    isToday: boolean;
    isWeekend: boolean;
};

export type SpanItem = { id: string; start: string; end: string };

export type WeekBar = {
    id: string;
    /** 0-based column of the first visible day in this week. */
    startCol: number;
    /** 0-based column of the last visible day in this week (inclusive). */
    endCol: number;
    lane: number;
    /** The real range extends before this week. */
    continuesBefore: boolean;
    /** The real range extends after this week. */
    continuesAfter: boolean;
};

export function parseISO(iso: string): Date {
    const [y, m, d] = iso.slice(0, 10).split('-').map(Number);

    return new Date(y, (m ?? 1) - 1, d ?? 1);
}

export function toISO(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
}

export function todayISO(): string {
    return toISO(new Date());
}

export function shiftDate(iso: string, days: number): string {
    const date = parseISO(iso);
    date.setDate(date.getDate() + days);

    return toISO(date);
}

/** Whole days from a to b (positive when b is later). */
export function dayDiff(a: string, b: string): number {
    const ms = parseISO(b).getTime() - parseISO(a).getTime();

    return Math.round(ms / 86_400_000);
}

export function startOfWeek(iso: string, weekStartsOn: 0 | 1 = 1): string {
    const date = parseISO(iso);
    const day = date.getDay();
    const diff = (day - weekStartsOn + 7) % 7;
    date.setDate(date.getDate() - diff);

    return toISO(date);
}

export function startOfMonth(iso: string): string {
    return iso.slice(0, 8) + '01';
}

export function shiftMonth(iso: string, months: number): string {
    const date = parseISO(startOfMonth(iso));
    date.setMonth(date.getMonth() + months);

    return toISO(date);
}

export function isWeekend(iso: string): boolean {
    const day = parseISO(iso).getDay();

    return day === 0 || day === 6;
}

export function monthLabel(iso: string): string {
    return parseISO(startOfMonth(iso)).toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    });
}

export function dayLabel(
    iso: string,
    options: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' },
): string {
    return parseISO(iso).toLocaleDateString(undefined, options);
}

/** Seven days starting at the week containing `anchor`. */
export function weekDays(
    anchor: string,
    weekStartsOn: 0 | 1 = 1,
    today = todayISO(),
): CalendarDay[] {
    const start = startOfWeek(anchor, weekStartsOn);
    const month = anchor.slice(0, 7);

    return Array.from({ length: 7 }, (_, i) => {
        const date = shiftDate(start, i);

        return {
            date,
            inMonth: date.slice(0, 7) === month,
            isToday: date === today,
            isWeekend: isWeekend(date),
        };
    });
}

export function weekRange(
    anchor: string,
    weekStartsOn: 0 | 1 = 1,
): { start: string; end: string } {
    const start = startOfWeek(anchor, weekStartsOn);

    return { start, end: shiftDate(start, 6) };
}

/** Six rows of seven days covering the month of `anchor`. */
export function monthGrid(
    anchor: string,
    weekStartsOn: 0 | 1 = 1,
    today = todayISO(),
): CalendarDay[][] {
    const first = startOfMonth(anchor);
    const gridStart = startOfWeek(first, weekStartsOn);
    const month = first.slice(0, 7);

    return Array.from({ length: 6 }, (_, row) =>
        Array.from({ length: 7 }, (_, col) => {
            const date = shiftDate(gridStart, row * 7 + col);

            return {
                date,
                inMonth: date.slice(0, 7) === month,
                isToday: date === today,
                isWeekend: isWeekend(date),
            };
        }),
    );
}

export function weekdayNames(weekStartsOn: 0 | 1 = 1): string[] {
    const base = startOfWeek('2024-01-07', weekStartsOn);

    return Array.from({ length: 7 }, (_, i) =>
        parseISO(shiftDate(base, i)).toLocaleDateString(undefined, {
            weekday: 'short',
        }),
    );
}

/**
 * Place spanning items into lanes for one week row. Greedy, stable: items are sorted by start
 * then by length (longest first) and take the lowest free lane.
 */
export function laneAssign(
    items: SpanItem[],
    week: string[],
): { bars: WeekBar[]; lanes: number } {
    if (week.length === 0) {
        return { bars: [], lanes: 0 };
    }

    const weekStart = week[0];
    const weekEnd = week[week.length - 1];

    const visible = items
        .filter((item) => item.start <= weekEnd && item.end >= weekStart)
        .sort(
            (a, b) =>
                a.start.localeCompare(b.start) ||
                dayDiff(b.start, b.end) - dayDiff(a.start, a.end) ||
                a.id.localeCompare(b.id),
        );

    const laneEnds: number[] = [];
    const bars: WeekBar[] = [];

    for (const item of visible) {
        const startCol = Math.max(0, dayDiff(weekStart, item.start));
        const endCol = Math.min(week.length - 1, dayDiff(weekStart, item.end));
        let lane = laneEnds.findIndex((end) => end < startCol);

        if (lane === -1) {
            lane = laneEnds.length;
            laneEnds.push(endCol);
        } else {
            laneEnds[lane] = endCol;
        }

        bars.push({
            id: item.id,
            startCol,
            endCol,
            lane,
            continuesBefore: item.start < weekStart,
            continuesAfter: item.end > weekEnd,
        });
    }

    return { bars, lanes: laneEnds.length };
}
