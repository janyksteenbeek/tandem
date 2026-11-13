/**
 * Chat bodies are plain text. Links are found here and rendered as real anchor elements by
 * the component, so nothing a participant types is ever interpreted as markup.
 */

export type TextSegment =
    | { type: 'text'; text: string }
    | { type: 'link'; text: string; href: string };

const URL_PATTERN =
    /\b((?:https?:\/\/|www\.)[^\s<>"'`]+|[a-z0-9._%+-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+)/gi;

/** Trailing punctuation that almost always belongs to the sentence, not the link. */
const TRAILING = /[.,;:!?'"»”’]+$/;

function trimTrailing(candidate: string): string {
    let link = candidate.replace(TRAILING, '');

    // A closing bracket only belongs to the link when it was opened inside it.
    while (/[)\]}]$/.test(link)) {
        const close = link[link.length - 1];
        const open = close === ')' ? '(' : close === ']' ? '[' : '{';
        const opens = link.split(open).length - 1;
        const closes = link.split(close).length - 1;

        if (closes <= opens) {
            break;
        }

        link = link.slice(0, -1).replace(TRAILING, '');
    }

    return link;
}

function hrefFor(link: string): string | null {
    if (/^[a-z0-9._%+-]+@/i.test(link) && !link.includes('/')) {
        return `mailto:${link}`;
    }

    const candidate = /^https?:\/\//i.test(link) ? link : `https://${link}`;

    try {
        const url = new URL(candidate);

        return url.protocol === 'http:' || url.protocol === 'https:'
            ? url.href
            : null;
    } catch {
        return null;
    }
}

export function linkify(text: string): TextSegment[] {
    const segments: TextSegment[] = [];
    let cursor = 0;

    const pushText = (value: string) => {
        if (value === '') {
            return;
        }

        const last = segments[segments.length - 1];

        if (last?.type === 'text') {
            last.text += value;
        } else {
            segments.push({ type: 'text', text: value });
        }
    };

    for (const match of text.matchAll(URL_PATTERN)) {
        const start = match.index ?? 0;
        const link = trimTrailing(match[0]);
        const href = hrefFor(link);

        pushText(text.slice(cursor, start));

        if (href === null || link === '') {
            pushText(match[0]);
        } else {
            segments.push({ type: 'link', text: link, href });
            pushText(match[0].slice(link.length));
        }

        cursor = start + match[0].length;
    }

    pushText(text.slice(cursor));

    return segments;
}
