import { describe, expect, it } from 'vitest';
import {
    dateRange,
    idealBurndown,
    linearScale,
    niceTicks,
    polyline,
    timeTicks,
} from '@/lib/projects/chartScales';

describe('chartScales', () => {
    it('maps a domain onto a range linearly', () => {
        const scale = linearScale([0, 10], [0, 100]);

        expect(scale(0)).toBe(0);
        expect(scale(5)).toBe(50);
        expect(scale(10)).toBe(100);
        expect(linearScale([3, 3], [0, 100])(3)).toBe(0);
    });

    it('produces nice ticks covering the domain', () => {
        expect(niceTicks(0, 10, 4)).toEqual([0, 2, 4, 6, 8, 10]);
        expect(niceTicks(0, 37, 4)).toEqual([0, 10, 20, 30, 40]);
        expect(niceTicks(0, 0)).toEqual([0]);
        expect(niceTicks(0, 1, 4)).toEqual([0, 0.2, 0.4, 0.6, 0.8, 1]);
    });

    it('builds an ideal line from scope to zero', () => {
        expect(idealBurndown(10, 5)).toEqual([10, 8, 6, 4, 2, 0]);
        expect(idealBurndown(3, 0)).toEqual([3, 0]);
    });

    it('expands date ranges and picks weekly ticks', () => {
        const dates = dateRange('2026-11-01', '2026-11-15');

        expect(dates).toHaveLength(15);
        expect(dates[0]).toBe('2026-11-01');
        expect(dates[14]).toBe('2026-11-15');
        expect(timeTicks(dates)).toEqual([
            '2026-11-01',
            '2026-11-08',
            '2026-11-15',
        ]);
        expect(timeTicks([])).toEqual([]);
    });

    it('formats polyline points', () => {
        expect(
            polyline([
                { x: 0, y: 1.234 },
                { x: 10, y: 5 },
            ]),
        ).toBe('0,1.23 10,5');
    });
});
