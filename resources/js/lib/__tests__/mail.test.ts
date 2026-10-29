import { describe, expect, it } from 'vitest';
import {
    chipClass,
    displayName,
    formatAddressList,
    initialsOf,
} from '@/lib/mail';
import { bytes, listTime } from '@/lib/time';

describe('mail helpers', () => {
    it('falls back to the mailbox name when a contact has no display name', () => {
        expect(displayName({ name: null, email: 'ada@example.org' })).toBe(
            'ada',
        );
        expect(
            displayName({ name: '  Ada Lovelace ', email: 'ada@example.org' }),
        ).toBe('Ada Lovelace');
        expect(displayName(null)).toBe('Unknown');
    });

    it('builds initials from names and addresses', () => {
        expect(initialsOf('Grace Hopper')).toBe('GH');
        expect(initialsOf('linus@kernel.test')).toBe('LK');
    });

    it('only allows known chip colours', () => {
        expect(chipClass('teal')).toBe('chip-teal');
        expect(chipClass('javascript:alert(1)')).toBe('chip-slate');
        expect(chipClass(null)).toBe('chip-slate');
    });

    it('formats address lists the way mail clients expect', () => {
        expect(
            formatAddressList([
                { name: 'Ada', email: 'ada@example.org' },
                { name: null, email: 'bob@example.org' },
            ]),
        ).toBe('Ada <ada@example.org>, bob@example.org');
    });
});

describe('time helpers', () => {
    it('renders compact list timestamps', () => {
        expect(listTime(new Date().toISOString())).toBe('now');
        expect(listTime(new Date(Date.now() - 5 * 60_000).toISOString())).toBe(
            '5m',
        );
        expect(listTime(null)).toBe('');
    });

    it('formats byte sizes', () => {
        expect(bytes(512)).toBe('512 B');
        expect(bytes(2048)).toBe('2 KB');
        expect(bytes(5 * 1024 * 1024)).toBe('5.0 MB');
    });
});
