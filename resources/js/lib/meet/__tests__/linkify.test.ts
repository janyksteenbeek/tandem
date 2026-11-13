import { describe, expect, it } from 'vitest';
import { linkify } from '@/lib/meet/linkify';

describe('linkify', () => {
    it('leaves plain text as one segment', () => {
        expect(linkify('no links here')).toEqual([
            { type: 'text', text: 'no links here' },
        ]);
    });

    it('finds a link and keeps sentence punctuation out of it', () => {
        expect(linkify('See https://example.com/notes.')).toEqual([
            { type: 'text', text: 'See ' },
            {
                type: 'link',
                text: 'https://example.com/notes',
                href: 'https://example.com/notes',
            },
            { type: 'text', text: '.' },
        ]);
    });

    it('does not swallow a bracket that surrounds the link', () => {
        const segments = linkify('(docs: https://example.com/a) and more');

        expect(segments[1]).toMatchObject({
            type: 'link',
            href: 'https://example.com/a',
        });
        expect(segments[2]).toEqual({ type: 'text', text: ') and more' });
    });

    it('keeps brackets that belong to the address', () => {
        const [link] = linkify(
            'https://en.wikipedia.org/wiki/Rust_(programming_language)',
        );

        expect(link).toMatchObject({
            type: 'link',
            text: 'https://en.wikipedia.org/wiki/Rust_(programming_language)',
        });
    });

    it('adds https to bare www addresses and mailto to email addresses', () => {
        expect(linkify('www.example.com')[0]).toMatchObject({
            href: 'https://www.example.com/',
        });
        expect(linkify('ada@example.com')[0]).toMatchObject({
            href: 'mailto:ada@example.com',
        });
    });

    it('never produces a link for script or data schemes, and keeps markup as text', () => {
        const text =
            'javascript:alert(1) <img src=x onerror=alert(1)> data:text/html,hi';
        const segments = linkify(text);

        expect(segments.every((segment) => segment.type === 'text')).toBe(true);
        expect(segments.map((segment) => segment.text).join('')).toBe(text);
    });

    it('preserves every character, including line breaks', () => {
        const text = 'one\nhttps://a.example/x?y=1&z=2\ntwo';

        expect(
            linkify(text)
                .map((segment) => segment.text)
                .join(''),
        ).toBe(text);
    });
});
