/**
 * Small scale helpers for the SVG charts. No library: a chart here is a handful of lines and
 * bars, and the scales stay readable.
 */
export type Scale = (value: number) => number;

export function linearScale(
    domain: [number, number],
    range: [number, number],
): Scale {
    const [d0, d1] = domain;
    const [r0, r1] = range;
    const span = d1 - d0;

    return (value: number) =>
        span === 0 ? r0 : r0 + ((value - d0) / span) * (r1 - r0);
}

/** "Nice" tick values covering [min, max] with roughly `count` steps. */
export function niceTicks(min: number, max: number, count = 4): number[] {
    if (!Number.isFinite(min) || !Number.isFinite(max)) {
        return [0];
    }

    if (max <= min) {
        return [min];
    }

    const rough = (max - min) / Math.max(count, 1);
    const power = Math.pow(10, Math.floor(Math.log10(rough)));
    const fraction = rough / power;
    const nice = fraction < 1.5 ? 1 : fraction < 3 ? 2 : fraction < 7 ? 5 : 10;
    const step = nice * power;
    const start = Math.floor(min / step) * step;
    const end = Math.ceil(max / step) * step;
    const ticks: number[] = [];

    for (let v = start; v <= end + step / 2; v += step) {
        ticks.push(Number(v.toFixed(10)));
    }

    return ticks;
}

/** Every `every`th date from a sorted list of ISO dates, always including the first. */
export function timeTicks(dates: string[], every = 7): string[] {
    if (dates.length === 0) {
        return [];
    }

    return dates.filter((_, i) => i % every === 0);
}

/** The `points` attribute of an SVG polyline. */
export function polyline(points: { x: number; y: number }[]): string {
    return points.map((p) => `${round(p.x)},${round(p.y)}`).join(' ');
}

/** Ideal burndown from `scope` to 0 over `daysTotal` days (inclusive of day 0). */
export function idealBurndown(scope: number, daysTotal: number): number[] {
    const days = Math.max(daysTotal, 1);

    return Array.from({ length: days + 1 }, (_, i) =>
        Number((scope - (scope / days) * i).toFixed(4)),
    );
}

/** Dates between start and end inclusive, ISO yyyy-mm-dd. */
export function dateRange(start: string, end: string): string[] {
    const dates: string[] = [];
    const cursor = new Date(start + 'T00:00:00Z');
    const last = new Date(end + 'T00:00:00Z');

    while (cursor <= last && dates.length < 400) {
        dates.push(cursor.toISOString().slice(0, 10));
        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return dates;
}

function round(n: number): number {
    return Math.round(n * 100) / 100;
}
