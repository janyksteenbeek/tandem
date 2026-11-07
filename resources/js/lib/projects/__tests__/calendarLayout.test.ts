import { describe, expect, it } from 'vitest';
import {
    dayDiff,
    laneAssign,
    monthGrid,
    shiftDate,
    startOfWeek,
    weekDays,
    weekRange,
} from '@/lib/projects/calendarLayout';

describe('calendarLayout', () => {
    it('shifts and diffs dates across month boundaries', () => {
        expect(shiftDate('2026-01-30', 3)).toBe('2026-02-02');
        expect(shiftDate('2026-03-01', -1)).toBe('2026-02-28');
        expect(dayDiff('2026-11-01', '2026-11-10')).toBe(9);
        expect(dayDiff('2026-11-10', '2026-11-01')).toBe(-9);
    });

    it('builds a six week month grid starting on monday', () => {
        const grid = monthGrid('2026-11-15', 1, '2026-11-07');

        expect(grid).toHaveLength(6);
        expect(grid[0][0].date).toBe('2026-10-26');
        expect(grid[0][0].inMonth).toBe(false);
        expect(grid[0][5].date).toBe('2026-10-31');
        expect(grid[1][0].date).toBe('2026-11-02');
        expect(grid.flat().find((d) => d.isToday)?.date).toBe('2026-11-07');
        expect(grid.flat().filter((d) => d.isWeekend)).toHaveLength(12);
    });

    it('starts weeks on sunday when asked', () => {
        expect(startOfWeek('2026-11-07', 0)).toBe('2026-11-01');
        expect(startOfWeek('2026-11-07', 1)).toBe('2026-11-02');
        expect(weekRange('2026-11-07', 1)).toEqual({
            start: '2026-11-02',
            end: '2026-11-08',
        });
        expect(weekDays('2026-11-07', 1).map((d) => d.date)[6]).toBe(
            '2026-11-08',
        );
    });

    it('assigns lanes greedily and clips bars to the week', () => {
        const week = [
            '2026-11-02',
            '2026-11-03',
            '2026-11-04',
            '2026-11-05',
            '2026-11-06',
            '2026-11-07',
            '2026-11-08',
        ];
        const { bars, lanes } = laneAssign(
            [
                { id: 'a', start: '2026-10-30', end: '2026-11-03' },
                { id: 'b', start: '2026-11-03', end: '2026-11-05' },
                { id: 'c', start: '2026-11-04', end: '2026-11-12' },
                { id: 'd', start: '2026-11-20', end: '2026-11-21' },
            ],
            week,
        );

        expect(lanes).toBe(2);
        expect(bars.find((b) => b.id === 'a')).toMatchObject({
            startCol: 0,
            endCol: 1,
            lane: 0,
            continuesBefore: true,
            continuesAfter: false,
        });
        expect(bars.find((b) => b.id === 'b')).toMatchObject({
            startCol: 1,
            endCol: 3,
            lane: 1,
        });
        expect(bars.find((b) => b.id === 'c')).toMatchObject({
            startCol: 2,
            endCol: 6,
            lane: 0,
            continuesAfter: true,
        });
        expect(bars.find((b) => b.id === 'd')).toBeUndefined();
    });
});
