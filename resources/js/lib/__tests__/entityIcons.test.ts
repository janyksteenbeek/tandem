import { Code, ShieldCheck, SquareKanban } from '@lucide/vue';
import { describe, expect, it } from 'vitest';
import { entityIcon, monogram } from '@/lib/entityIcons';

describe('entityIcon', () => {
    it('resolves stored lucide ids and their aliases', () => {
        expect(entityIcon('code')).toBe(Code);
        expect(entityIcon('shield-check')).toBe(ShieldCheck);
        expect(entityIcon('kanban')).toBe(SquareKanban);
    });

    it('returns null for unknown or empty names', () => {
        expect(entityIcon('not-an-icon')).toBeNull();
        expect(entityIcon(null)).toBeNull();
        expect(entityIcon(undefined)).toBeNull();
    });
});

describe('monogram', () => {
    it('takes the first letters of the first two words', () => {
        expect(monogram('Portivio Frontier')).toBe('PF');
        expect(monogram('QA')).toBe('Q');
        expect(monogram('reporting-redesign')).toBe('RR');
        expect(monogram('')).toBe('?');
    });
});
