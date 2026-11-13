import { describe, expect, it } from 'vitest';
import {
    buildScale,
    clampRange,
    ZOOMS,
    zoomIn,
    zoomOut,
} from '@/lib/projects/timelineScale';
import type { TimelineRow } from '@/lib/projects/timelineScale';

const row = (
    id: string,
    start: string | null,
    end: string | null,
    children: TimelineRow[] = [],
): TimelineRow => ({
    id,
    kind: 'issue',
    label: id,
    start,
    end,
    progress: 0,
    parentId: null,
    color: null,
    dependsOn: [],
    children,
});

describe('timelineScale', () => {
    it('maps dates to pixels and back per zoom', () => {
        const scale = buildScale('2026-11-02', '2026-11-30', 'week');

        expect(scale.dayWidth).toBe(ZOOMS.week);
        expect(scale.xForDate('2026-11-02')).toBe(0);
        expect(scale.xForDate('2026-11-05')).toBe(3 * ZOOMS.week);
        expect(scale.dateForX(3 * ZOOMS.week + 2)).toBe('2026-11-05');
        expect(scale.totalWidth).toBe(29 * ZOOMS.week);
        expect(scale.ticks[0].date).toBe('2026-11-02');
        expect(scale.ticks).toHaveLength(5);
    });

    it('produces month ticks for coarse zooms and day ticks for fine ones', () => {
        expect(
            buildScale('2026-01-15', '2026-04-10', 'month').ticks.map(
                (t) => t.date,
            ),
        ).toEqual(['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01']);
        expect(
            buildScale('2026-11-01', '2026-11-03', 'day').ticks,
        ).toHaveLength(3);
    });

    it('pads the range around rows and today, including nested rows', () => {
        const range = clampRange(
            [
                row('a', '2026-11-10', '2026-11-20', [
                    row('b', '2026-12-01', '2026-12-05'),
                ]),
            ],
            '2026-11-07',
            'week',
        );

        expect(range.from <= '2026-10-24').toBe(true);
        expect(range.to >= '2026-12-19').toBe(true);
        expect(new Date(range.from + 'T00:00:00').getDay()).toBe(1);
    });

    it('keeps a minimum span when nothing is dated', () => {
        const range = clampRange([row('a', null, null)], '2026-11-07', 'month');

        expect(range.to > '2027-07-01').toBe(true);
    });

    it('steps zoom levels', () => {
        expect(zoomIn('month')).toBe('week');
        expect(zoomIn('day')).toBe('day');
        expect(zoomOut('quarter')).toBe('quarter');
        expect(zoomOut('week')).toBe('month');
    });
});
