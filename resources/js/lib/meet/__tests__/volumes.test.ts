import { beforeEach, describe, expect, it } from 'vitest';
import {
    canAdjustVolume,
    clampVolume,
    loadVolumes,
    parseVolumes,
    saveVolumes,
    withVolume,
} from '@/lib/meet/volumes';

describe('clampVolume', () => {
    it('keeps a level between silent and normal', () => {
        expect(clampVolume(0.4)).toBe(0.4);
        expect(clampVolume(-1)).toBe(0);
        expect(clampVolume(3)).toBe(1);
    });

    it('falls back to normal volume for anything that is not a level', () => {
        expect(clampVolume(undefined)).toBe(1);
        expect(clampVolume('0.2')).toBe(1);
        expect(clampVolume(Number.NaN)).toBe(1);
    });
});

describe('withVolume', () => {
    it('changes one person and leaves the given levels untouched', () => {
        const before = { p_a: 0.5 };
        const after = withVolume(before, 'p_b', 0.2);

        expect(after).toEqual({ p_a: 0.5, p_b: 0.2 });
        expect(before).toEqual({ p_a: 0.5 });
    });

    it('forgets someone who is back at normal volume', () => {
        expect(withVolume({ p_a: 0.5 }, 'p_a', 1)).toEqual({});
    });

    it('keeps someone who was silenced', () => {
        expect(withVolume({}, 'p_a', 0)).toEqual({ p_a: 0 });
    });
});

describe('parseVolumes', () => {
    it('reads the levels that were saved', () => {
        expect(parseVolumes('{"p_a":0.3,"p_b":0}')).toEqual({
            p_a: 0.3,
            p_b: 0,
        });
    });

    it('drops entries that are not a level below normal', () => {
        expect(
            parseVolumes('{"p_a":1,"p_b":"loud","p_c":null,"p_d":-2,"p_e":7}'),
        ).toEqual({ p_d: 0 });
    });

    it('has nothing when the stored value is not the shape we expect', () => {
        expect(parseVolumes(null)).toEqual({});
        expect(parseVolumes('not json')).toEqual({});
        expect(parseVolumes('[0.5]')).toEqual({});
        expect(parseVolumes('null')).toEqual({});
    });
});

describe('remembering levels', () => {
    beforeEach(() => window.sessionStorage.clear());

    it('keeps them per call', () => {
        saveVolumes('call-1', { p_a: 0.25 });

        expect(loadVolumes('call-1')).toEqual({ p_a: 0.25 });
        expect(loadVolumes('call-2')).toEqual({});
    });

    it('leaves nothing behind once everyone is back at normal volume', () => {
        saveVolumes('call-1', { p_a: 0.25 });
        saveVolumes('call-1', {});

        expect(window.sessionStorage.length).toBe(0);
    });
});

describe('canAdjustVolume', () => {
    it('is true where a media element takes the volume it is given', () => {
        expect(canAdjustVolume()).toBe(true);
    });
});
