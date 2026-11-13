import { describe, expect, it } from 'vitest';
import type { StageLayout, StageTile } from '@/lib/calls/playback';
import { rectStyle, stageRects } from '@/lib/calls/stage';
import type { RecordingTrack } from '@/types';

const screen = (file: string): RecordingTrack => ({
    file,
    kind: 'video',
    source: 'screen_share',
    codec: 'vp9',
    participant: { identity: 'p_a', name: 'Ada' },
    start_ms: 0,
    duration_ms: 1000,
    width: 1920,
    height: 1080,
    size_bytes: 0,
    url: '',
});

const tile = (identity: string): StageTile => ({
    identity,
    name: identity,
    camera: null,
    hasAudio: true,
    speaking: false,
});

const layout = (partial: Partial<StageLayout>): StageLayout => ({
    mode: 'empty',
    screen: null,
    screens: [],
    tiles: [],
    ...partial,
});

describe('stageRects', () => {
    it('gives a lone shared screen the whole stage', () => {
        const s = screen('s1');
        const rects = stageRects(
            layout({ mode: 'screen', screen: s, screens: [s] }),
            1280,
            720,
        );

        expect(rects.screen).toEqual({
            left: 0,
            top: 0,
            width: 1280,
            height: 720,
        });
        expect(rects.strip).toBe('none');
    });

    it('puts people in a side strip and leaves the rest to the screen', () => {
        const s = screen('s1');
        const rects = stageRects(
            layout({
                mode: 'screen',
                screen: s,
                screens: [s],
                tiles: [tile('p_a'), tile('p_b')],
            }),
            1280,
            720,
        );

        expect(rects.strip).toBe('side');
        expect(rects.tiles.p_a.width).toBe(184);
        expect(rects.tiles.p_a.height).toBeCloseTo(103.5);
        expect(rects.tiles.p_b.top).toBeCloseTo(
            rects.tiles.p_a.top + 103.5 + 8,
        );
        expect(rects.screen?.width).toBe(1280 - 184 - 16);
        expect(rects.screen?.height).toBe(720);
        // Nothing overlaps the screen.
        expect(rects.tiles.p_a.left).toBeGreaterThanOrEqual(
            (rects.screen?.width ?? 0) + 8,
        );
    });

    it('shrinks strip tiles so many people still fit without scrolling', () => {
        const s = screen('s1');
        const tiles = Array.from({ length: 8 }, (_, i) => tile(`p_${i}`));
        const rects = stageRects(
            layout({ mode: 'screen', screen: s, screens: [s], tiles }),
            1280,
            720,
        );
        const last = rects.tiles.p_7;

        expect(last.top + last.height).toBeLessThanOrEqual(720);
        expect(last.width).toBeLessThan(184);
        expect(rects.screen?.width).toBeGreaterThan(1100);
    });

    it('moves the strip to the bottom on a narrow player', () => {
        const s = screen('s1');
        const rects = stageRects(
            layout({
                mode: 'screen',
                screen: s,
                screens: [s],
                tiles: [tile('p_a'), tile('p_b')],
            }),
            390,
            300,
        );

        expect(rects.strip).toBe('bottom');
        expect(rects.screen?.width).toBe(390);
        expect(rects.tiles.p_a.top).toBeGreaterThanOrEqual(
            (rects.screen?.height ?? 0) + 8,
        );
        expect(rects.tiles.p_b.left).toBeGreaterThan(rects.tiles.p_a.left);
    });

    it('offers the other live screen shares as thumbnails', () => {
        const a = screen('s1');
        const b = screen('s2');
        const rects = stageRects(
            layout({
                mode: 'screen',
                screen: a,
                screens: [a, b],
                tiles: [tile('p_a')],
            }),
            1280,
            720,
        );

        expect(Object.keys(rects.thumbnails)).toEqual(['s2']);
        expect(rects.thumbnails.s2.top).toBeLessThan(rects.tiles.p_a.top);
    });

    it('centres a grid of people and keeps tiles 16:9', () => {
        const rects = stageRects(
            layout({
                mode: 'grid',
                tiles: [tile('p_a'), tile('p_b'), tile('p_c')],
            }),
            1280,
            720,
        );
        const a = rects.tiles.p_a;
        const c = rects.tiles.p_c;

        expect(a.width / a.height).toBeCloseTo(16 / 9);
        expect(rects.tiles.p_b.top).toBe(a.top);
        // The third tile sits alone on the second row, centred.
        expect(c.top).toBeGreaterThan(a.top);
        expect(c.left + c.width / 2).toBeCloseTo(640);
        expect(c.top + c.height).toBeLessThanOrEqual(720);
    });

    it('has nothing to place for audio-only and empty moments, or before it is measured', () => {
        expect(
            stageRects(
                layout({ mode: 'audio', tiles: [tile('p_a')] }),
                1280,
                720,
            ).tiles,
        ).toEqual({});
        expect(
            stageRects(layout({ mode: 'grid', tiles: [tile('p_a')] }), 0, 0)
                .tiles,
        ).toEqual({});
    });

    it('keeps an unplaced element in the page but out of sight', () => {
        expect(rectStyle(null).visibility).toBe('hidden');
        expect(rectStyle({ left: 1, top: 2, width: 3, height: 4 })).toEqual({
            left: '1px',
            top: '2px',
            width: '3px',
            height: '4px',
        });
    });
});
