export type TextPart =
    | { type: 'text'; value: string }
    | { type: 'link'; value: string; href: string };

const URL_PATTERN = /\b(?:https?:\/\/|www\.)[^\s<>"']+/gi;
const TRAILING = /[.,;:!?)\]}'"]+$/;

/**
 * Splits a plain-text chat message into text and link parts, so links can be rendered as real
 * anchors without ever interpreting what a participant typed as markup. Only http(s) links are
 * recognised; trailing punctuation stays text, and a closing bracket stays part of the link
 * when the link opened it (…/wiki/Foo_(bar)).
 */
export function linkify(text: string): TextPart[] {
    const parts: TextPart[] = [];
    let cursor = 0;

    for (const match of text.matchAll(URL_PATTERN)) {
        const start = match.index ?? 0;
        let raw = match[0];
        const trailing = raw.match(TRAILING)?.[0] ?? '';

        if (trailing) {
            raw = raw.slice(0, raw.length - trailing.length);

            // Hand back closing brackets the URL itself opened.
            for (const char of trailing) {
                const open = char === ')' ? '(' : char === ']' ? '[' : null;

                if (open && raw.split(open).length > raw.split(char).length) {
                    raw += char;
                } else {
                    break;
                }
            }
        }

        const href = safeHref(raw);

        if (!href) {
            continue;
        }

        if (start > cursor) {
            parts.push({ type: 'text', value: text.slice(cursor, start) });
        }

        parts.push({ type: 'link', value: raw, href });
        cursor = start + raw.length;
    }

    if (cursor < text.length) {
        parts.push({ type: 'text', value: text.slice(cursor) });
    }

    return parts;
}

function safeHref(raw: string): string | null {
    try {
        const url = new URL(raw.startsWith('www.') ? `https://${raw}` : raw);

        return url.protocol === 'http:' || url.protocol === 'https:'
            ? url.href
            : null;
    } catch {
        return null;
    }
}
