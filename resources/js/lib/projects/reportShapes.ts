import type { StatusCategory } from '@/types';

/** Pure helpers behind the reports page: range presets, derived figures and chart shapes. */

export type RangePreset = {
    key: '2w' | '4w' | '12w' | 'quarter';
    label: string;
    days: (today: Date) => number;
};

export type ThroughputWeek = {
    week: string;
    created: number;
    completed: number;
};

export type CategoryCounts = Record<StatusCategory, number>;

export type BreakdownSegment = {
    category: StatusCategory;
    count: number;
    /** 0..1 share of the total. */
    share: number;
};

/** Whole days from the start of the current quarter to today, never below the API's minimum. */
export function quarterDays(today: Date): number {
    const start = new Date(
        Date.UTC(
            today.getUTCFullYear(),
            Math.floor(today.getUTCMonth() / 3) * 3,
            1,
        ),
    );
    const utcToday = Date.UTC(
        today.getUTCFullYear(),
        today.getUTCMonth(),
        today.getUTCDate(),
    );

    return Math.max(7, Math.round((utcToday - start.getTime()) / 86_400_000));
}

export const RANGE_PRESETS: RangePreset[] = [
    { key: '2w', label: 'Last 2 weeks', days: () => 14 },
    { key: '4w', label: 'Last 4 weeks', days: () => 28 },
    { key: '12w', label: 'Last 12 weeks', days: () => 84 },
    { key: 'quarter', label: 'This quarter', days: quarterDays },
];

/** The preset whose day count matches, so the toggle reflects the URL. */
export function presetForDays(
    days: number,
    today: Date,
): RangePreset['key'] | null {
    return RANGE_PRESETS.find((p) => p.days(today) === days)?.key ?? null;
}

/** Average completed issues per week over the given weeks (0 when empty). */
export function throughputPerWeek(weeks: ThroughputWeek[]): number {
    if (weeks.length === 0) {
        return 0;
    }

    return weeks.reduce((sum, w) => sum + w.completed, 0) / weeks.length;
}

/** Segments for a stacked category bar, in workflow order, skipping empty categories. */
export function categoryBreakdown(counts: CategoryCounts): BreakdownSegment[] {
    const order: StatusCategory[] = [
        'backlog',
        'unstarted',
        'started',
        'completed',
        'canceled',
    ];
    const total = order.reduce((sum, c) => sum + (counts[c] ?? 0), 0);

    return order
        .filter((c) => (counts[c] ?? 0) > 0)
        .map((c) => ({
            category: c,
            count: counts[c],
            share: total === 0 ? 0 : counts[c] / total,
        }));
}

/** "6h", "1.5d" or "3w" from a number of hours; null when unknown. */
export function formatHours(hours: number | null | undefined): string | null {
    if (hours === null || hours === undefined || Number.isNaN(hours)) {
        return null;
    }

    if (hours < 24) {
        return `${Math.round(hours)}h`;
    }

    const days = hours / 24;

    if (days < 14) {
        return `${Math.round(days * 10) / 10}d`;
    }

    return `${Math.round((days / 7) * 10) / 10}w`;
}

/** Short "12 Jan" label for a week's start date. */
export function weekLabel(isoDate: string): string {
    const [y, m, d] = isoDate.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
}

/** Team rows from a YEARWEEK-keyed list padded to the last `count` ISO weeks. */
export function padWeeks(
    rows: { week: string; completed: number }[],
    count: number,
    today: Date,
): { week: string; label: string; completed: number }[] {
    const byKey = new Map(rows.map((r) => [r.week, r.completed]));
    const monday = isoMonday(today);
    const out: { week: string; label: string; completed: number }[] = [];

    for (let i = count - 1; i >= 0; i--) {
        const date = new Date(monday.getTime() - i * 7 * 86_400_000);
        const key = yearWeek(date);
        out.push({
            week: key,
            label: weekLabel(date.toISOString().slice(0, 10)),
            completed: byKey.get(key) ?? 0,
        });
    }

    return out;
}

/** The Monday of the ISO week containing the date (UTC). */
export function isoMonday(date: Date): Date {
    const utc = new Date(
        Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()),
    );
    const day = utc.getUTCDay() || 7;
    utc.setUTCDate(utc.getUTCDate() - (day - 1));

    return utc;
}

/** MySQL YEARWEEK(date, 3) equivalent: ISO year and week as "YYYYWW". */
export function yearWeek(date: Date): string {
    const target = isoMonday(date);
    const thursday = new Date(target.getTime() + 3 * 86_400_000);
    const year = thursday.getUTCFullYear();
    const firstThursday = new Date(Date.UTC(year, 0, 4));
    const week =
        1 +
        Math.round(
            (thursday.getTime() - isoMonday(firstThursday).getTime()) /
                (7 * 86_400_000),
        );

    return `${year}${String(week).padStart(2, '0')}`;
}
