import type { CycleSummary } from '@/types';

export const CYCLE_STATUS: Record<
    CycleSummary['status'],
    { label: string; className: string }
> = {
    upcoming: {
        label: 'Upcoming',
        className: 'bg-muted text-muted-foreground',
    },
    active: {
        label: 'Active',
        className: 'bg-brand-soft text-brand-soft-foreground',
    },
    completed: { label: 'Completed', className: 'bg-success/12 text-success' },
};

const fmt = (iso: string, year = false) =>
    new Date(iso + 'T00:00:00').toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(year ? { year: 'numeric' } : {}),
    });

/** "Nov 3 – Nov 16" (adds the year when the cycle isn't this year). */
export function cycleDates(
    cycle: Pick<CycleSummary, 'starts_at' | 'ends_at'>,
): string {
    const sameYear =
        cycle.ends_at.slice(0, 4) === String(new Date().getFullYear());

    return `${fmt(cycle.starts_at)} – ${fmt(cycle.ends_at, !sameYear)}`;
}

export function daysLeft(
    cycle: Pick<CycleSummary, 'ends_at' | 'status'>,
): number {
    if (cycle.status === 'completed') {
        return 0;
    }

    const end = new Date(cycle.ends_at + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return Math.max(
        0,
        Math.round((end.getTime() - today.getTime()) / 86400000) + 1,
    );
}

/** Next start date following the team's cadence, as yyyy-mm-dd. */
export function suggestedDates(
    lastEnd: string | null,
    lengthWeeks: number,
    cooldownWeeks: number,
): { starts_at: string; ends_at: string } {
    const start = lastEnd ? new Date(lastEnd + 'T00:00:00') : new Date();

    if (lastEnd) {
        start.setDate(start.getDate() + 1 + cooldownWeeks * 7);
    }

    const end = new Date(start);
    end.setDate(end.getDate() + lengthWeeks * 7 - 1);
    const iso = (d: Date) =>
        new Date(d.getTime() - d.getTimezoneOffset() * 60000)
            .toISOString()
            .slice(0, 10);

    return { starts_at: iso(start), ends_at: iso(end) };
}
