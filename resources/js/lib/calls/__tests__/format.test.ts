import { describe, expect, it } from 'vitest';
import {
    extractMeetingCode,
    formatClock,
    formatDuration,
    formatSize,
    parseEmails,
} from '@/lib/calls/format';
import { linkify } from '@/lib/calls/linkify';

describe('formatting', () => {
    it('writes durations the way people say them', () => {
        expect(formatDuration(45)).toBe('45 s');
        expect(formatDuration(60)).toBe('1 min');
        expect(formatDuration(3600)).toBe('1 h');
        expect(formatDuration(3900)).toBe('1 h 5 min');
        expect(formatDuration(null)).toBe('');
    });

    it('writes player clocks', () => {
        expect(formatClock(7_400)).toBe('0:07');
        expect(formatClock(765_000)).toBe('12:45');
        expect(formatClock(3_723_000)).toBe('1:02:03');
        expect(formatClock(-5)).toBe('0:00');
    });

    it('writes sizes up to gigabytes', () => {
        expect(formatSize(512)).toBe('512 B');
        expect(formatSize(2048)).toBe('2 KB');
        expect(formatSize(5 * 1024 ** 2)).toBe('5.0 MB');
        expect(formatSize(1.5 * 1024 ** 3)).toBe('1.50 GB');
    });
});

describe('meeting codes', () => {
    it('finds the code in a bare code, a link, or messy input', () => {
        expect(extractMeetingCode('abc-defg-hij')).toBe('abc-defg-hij');
        expect(
            extractMeetingCode(
                ' https://tandem.test/meet/ABC-defg-hij?invite=x ',
            ),
        ).toBe('abc-defg-hij');
        expect(extractMeetingCode('abcdefghij')).toBeNull();
        expect(extractMeetingCode('')).toBeNull();
    });
});

describe('recipient parsing', () => {
    it('reads pasted lists and reports what is not an address', () => {
        expect(
            parseEmails(
                'Ada <Ada@Example.com>, bo@example.com; bo@example.com nope@ x',
            ),
        ).toEqual({
            emails: ['ada@example.com', 'bo@example.com'],
            invalid: ['nope@'],
        });
        expect(parseEmails('   ')).toEqual({ emails: [], invalid: [] });
    });
});

describe('linkify', () => {
    it('leaves plain text alone', () => {
        expect(linkify('no links here <b>bold</b>')).toEqual([
            { type: 'text', value: 'no links here <b>bold</b>' },
        ]);
    });

    it('splits links out and keeps trailing punctuation as text', () => {
        expect(
            linkify('See https://example.com/a?b=1, then www.tandem.test.'),
        ).toEqual([
            { type: 'text', value: 'See ' },
            {
                type: 'link',
                value: 'https://example.com/a?b=1',
                href: 'https://example.com/a?b=1',
            },
            { type: 'text', value: ', then ' },
            {
                type: 'link',
                value: 'www.tandem.test',
                href: 'https://www.tandem.test/',
            },
            { type: 'text', value: '.' },
        ]);
    });

    it('keeps a closing bracket the link itself opened', () => {
        expect(linkify('(https://en.wikipedia.org/wiki/Foo_(bar))')).toEqual([
            { type: 'text', value: '(' },
            {
                type: 'link',
                value: 'https://en.wikipedia.org/wiki/Foo_(bar)',
                href: 'https://en.wikipedia.org/wiki/Foo_(bar)',
            },
            { type: 'text', value: ')' },
        ]);
    });

    it('never produces a link for a scheme other than http or https', () => {
        expect(linkify('javascript:alert(1) data:text/html,x')).toEqual([
            { type: 'text', value: 'javascript:alert(1) data:text/html,x' },
        ]);
    });
});
