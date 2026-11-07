import { describe, expect, it } from 'vitest';
import { between, GAP, MIN_GAP, rebalance } from '@/lib/projects/positioning';

describe('positioning', () => {
    it('places first, before, after and between', () => {
        expect(between(null, null)).toEqual({
            position: GAP,
            rebalance: false,
        });
        expect(between(null, 100)).toEqual({
            position: 100 - GAP,
            rebalance: false,
        });
        expect(between(100, null)).toEqual({
            position: 100 + GAP,
            rebalance: false,
        });
        expect(between(100, 200)).toEqual({ position: 150, rebalance: false });
    });

    it('flags a rebalance when neighbours are too close', () => {
        const result = between(1, 1 + MIN_GAP / 2);

        expect(result.rebalance).toBe(true);
        expect(result.position).toBeGreaterThan(1);
    });

    it('rebalances evenly', () => {
        expect(rebalance(3)).toEqual([GAP, 2 * GAP, 3 * GAP]);
    });
});
