import { describe, expect, it } from 'vitest';
import {
    messageText,
    prefillFromConversation,
    quotedSnippet,
} from '@/lib/projects/issuePrefill';
import type { Message } from '@/types';

const message = (overrides: Partial<Message> = {}): Message => ({
    uuid: 'm-1',
    direction: 'inbound',
    from: { name: 'Ada Lovelace', email: 'ada@customer.example' },
    to: [],
    cc: [],
    bcc: [],
    reply_to: [],
    subject: 'Re: Export is empty',
    snippet: 'The export is empty',
    html_body: null,
    text_body:
        'The export is empty since Monday.\n\n> earlier quote\n\nThanks,\nAda',
    sent_at: '2026-11-01T10:00:00Z',
    is_read: true,
    is_draft: false,
    is_starred: false,
    has_attachments: false,
    delivery_status: 'none',
    delivery_error: null,
    author: null,
    attachments: [],
    message_id: null,
    ...overrides,
});

describe('messageText', () => {
    it('prefers the text body and drops quoted lines', () => {
        expect(messageText(message())).toBe(
            'The export is empty since Monday.\n\nThanks,\nAda',
        );
    });

    it('falls back to a stripped html body', () => {
        expect(
            messageText(
                message({
                    text_body: null,
                    html_body:
                        '<style>p{}</style><p>Hello <b>there</b></p><p>Second &amp; last</p>',
                }),
            ),
        ).toBe('Hello there\nSecond & last');
    });

    it('stops at a signature or reply marker', () => {
        expect(
            messageText(
                message({
                    text_body: 'Body\n-- \nSignature\nOn Monday x wrote:',
                }),
            ),
        ).toBe('Body');
    });
});

describe('quotedSnippet', () => {
    it('wraps the escaped text in a blockquote with the sender', () => {
        expect(quotedSnippet(message({ text_body: 'Needs <b>fix</b>' }))).toBe(
            '<p><em>From Ada Lovelace:</em></p><blockquote><p>Needs &lt;b&gt;fix&lt;/b&gt;</p></blockquote>',
        );
    });

    it('truncates long bodies', () => {
        const long = 'x'.repeat(700);

        expect(quotedSnippet(message({ text_body: long }), 100)).toContain(
            `${'x'.repeat(100)}…`,
        );
    });

    it('returns nothing when there is no body', () => {
        expect(quotedSnippet(message({ text_body: null, snippet: null }))).toBe(
            '',
        );
    });
});

describe('prefillFromConversation', () => {
    it('uses the subject without reply prefixes and quotes the last inbound message', () => {
        const prefill = prefillFromConversation({
            uuid: 'c-1',
            subject: 'Re: Fwd: Export is empty',
            messages: [
                message({ uuid: 'a', text_body: 'first' }),
                message({
                    uuid: 'b',
                    direction: 'outbound',
                    text_body: 'ours',
                }),
                message({ uuid: 'c', text_body: 'latest inbound' }),
                message({ uuid: 'd', is_draft: true, text_body: 'draft' }),
            ],
        });

        expect(prefill.title).toBe('Export is empty');
        expect(prefill.conversation_uuid).toBe('c-1');
        expect(prefill.conversation_subject).toBe('Re: Fwd: Export is empty');
        expect(prefill.description_html).toContain('latest inbound');
    });

    it('accepts an explicit message and keeps the raw subject when only prefixes remain', () => {
        const prefill = prefillFromConversation(
            { uuid: 'c-2', subject: 'Re:', messages: [] },
            message({ text_body: 'given' }),
        );

        expect(prefill.title).toBe('Re:');
        expect(prefill.description_html).toContain('given');
    });

    it('carries no mail content when there is no message', () => {
        expect(
            prefillFromConversation({
                uuid: 'c-3',
                subject: null,
                messages: [],
            }).description_html,
        ).toBe('');
    });
});
