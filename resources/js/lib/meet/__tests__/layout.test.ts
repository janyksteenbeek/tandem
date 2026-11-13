import { describe, expect, it } from 'vitest';
import {
    computeGrid,
    orderParticipants,
    pickStageScreen,
    stageMode,
} from '@/lib/meet/layout';

describe('computeGrid', () => {
    it('gives a single tile the largest 16:9 box that fits', () => {
        expect(computeGrid(1, 1600, 900, 0)).toMatchObject({
            columns: 1,
            rows: 1,
            tileWidth: 1600,
            tileHeight: 900,
        });
    });

    it('puts two people side by side on a wide stage and stacked on a tall one', () => {
        expect(computeGrid(2, 1600, 600)).toMatchObject({
            columns: 2,
            rows: 1,
        });
        expect(computeGrid(2, 400, 800)).toMatchObject({ columns: 1, rows: 2 });
    });

    it('uses a 2x2 grid for four and 3 columns for five or six on a desktop stage', () => {
        expect(computeGrid(4, 1400, 800)).toMatchObject({
            columns: 2,
            rows: 2,
        });
        expect(computeGrid(5, 1400, 800)).toMatchObject({
            columns: 3,
            rows: 2,
        });
        expect(computeGrid(6, 1400, 800)).toMatchObject({
            columns: 3,
            rows: 2,
        });
    });

    it('never lets the grid overflow the stage, gaps included', () => {
        for (let count = 1; count <= 12; count++) {
            const { columns, rows, tileWidth, tileHeight } = computeGrid(
                count,
                1280,
                640,
                12,
            );

            expect(columns * rows).toBeGreaterThanOrEqual(count);
            expect(
                columns * tileWidth + (columns - 1) * 12,
            ).toBeLessThanOrEqual(1280);
            expect(rows * tileHeight + (rows - 1) * 12).toBeLessThanOrEqual(
                640,
            );
        }
    });

    it('returns empty tiles before the stage has been measured', () => {
        expect(computeGrid(3, 0, 0)).toMatchObject({
            tileWidth: 0,
            tileHeight: 0,
        });
        expect(computeGrid(0, 800, 600)).toMatchObject({ tileWidth: 0 });
    });
});

describe('stageMode', () => {
    it('focuses whenever a screen is shared or someone is pinned', () => {
        expect(stageMode(1, true, false)).toBe('focus');
        expect(stageMode(5, false, true)).toBe('focus');
    });

    it('shows one person alone, two as a duo, more as a grid', () => {
        expect(stageMode(1, false, false)).toBe('solo');
        expect(stageMode(2, false, false)).toBe('duo');
        expect(stageMode(3, false, false)).toBe('grid');
    });
});

describe('pickStageScreen', () => {
    const mine = { id: 'screen:me', isLocal: true };
    const ada = { id: 'screen:ada', isLocal: false };
    const bob = { id: 'screen:bob', isLocal: false };

    it('has nothing to show without shares', () => {
        expect(pickStageScreen([], null)).toBeNull();
    });

    it('keeps the screen the viewer picked while it is still shared', () => {
        expect(pickStageScreen([ada, bob], 'screen:ada')).toBe(ada);
    });

    it('falls back to the newest remote screen when the pick is gone', () => {
        expect(pickStageScreen([ada, bob], 'screen:gone')).toBe(bob);
    });

    it('prefers someone else’s screen over your own', () => {
        expect(pickStageScreen([ada, mine], null)).toBe(ada);
        expect(pickStageScreen([mine], null)).toBe(mine);
    });
});

describe('orderParticipants', () => {
    const person = (identity: string, joinedAt: number, isLocal = false) => ({
        identity,
        joinedAt,
        isLocal,
    });

    it('puts you first, then everyone by arrival', () => {
        const order = orderParticipants(
            [person('c', 3), person('me', 2, true), person('a', 1)],
            [],
            {},
            6,
        );

        expect(order.map((p) => p.identity)).toEqual(['me', 'a', 'c']);
    });

    it('keeps the existing order stable when someone speaks who is already visible', () => {
        const people = [person('me', 0, true), person('a', 1), person('b', 2)];
        const order = orderParticipants(
            people,
            ['me', 'a', 'b'],
            { b: 999 },
            6,
        );

        expect(order.map((p) => p.identity)).toEqual(['me', 'a', 'b']);
    });

    it('swaps a hidden speaker in for the quietest visible person', () => {
        const people = [
            person('me', 0, true),
            person('a', 1),
            person('b', 2),
            person('c', 3),
        ];
        const order = orderParticipants(
            people,
            ['me', 'a', 'b', 'c'],
            { a: 50, c: 100 },
            3,
        );

        expect(order.map((p) => p.identity)).toEqual(['me', 'a', 'c', 'b']);
    });

    it('drops people who left and appends newcomers', () => {
        const order = orderParticipants(
            [person('me', 0, true), person('new', 9)],
            ['me', 'gone'],
            {},
            6,
        );

        expect(order.map((p) => p.identity)).toEqual(['me', 'new']);
    });
});
