import { describe, expect, it } from 'vitest';
import { visitOptions } from '@/lib/viewTransitions';

describe('visitOptions', () => {
    it('enables a view transition for a full page visit', () => {
        expect(visitOptions('/mail', {})).toEqual({ viewTransition: true });
        expect(visitOptions('/mail', { only: [] })).toEqual({
            viewTransition: true,
        });
    });

    it('leaves partial reloads alone', () => {
        expect(visitOptions('/mail', { only: ['mailNav', 'counts'] })).toEqual({
            viewTransition: false,
        });
    });

    it('skips prefetches and async visits', () => {
        expect(visitOptions('/mail', { prefetch: true })).toEqual({
            viewTransition: false,
        });
        expect(visitOptions('/mail', { async: true })).toEqual({
            viewTransition: false,
        });
    });

    it('keeps an explicit request for a transition, even on a partial visit', () => {
        const callback = () => {};

        expect(
            visitOptions('/mail', {
                only: ['conversation'],
                viewTransition: true,
            }),
        ).toEqual({});
        expect(
            visitOptions('/mail', {
                only: ['conversation'],
                viewTransition: callback,
            }),
        ).toEqual({});
    });
});
