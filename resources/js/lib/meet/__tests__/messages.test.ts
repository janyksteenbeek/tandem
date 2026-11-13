import { describe, expect, it } from 'vitest';
import {
    continuesPrevious,
    countUnread,
    firstConfirmedId,
    lastConfirmedId,
    markFailed,
    markPending,
    mergeMessages,
    removeLocal,
    upsertMessage,
} from '@/lib/meet/messages';
import type { LocalMessage } from '@/lib/meet/types';
import type { CallMessage } from '@/types';

const author = (uuid: string) => ({
    uuid,
    name: uuid,
    initials: uuid.slice(0, 1).toUpperCase(),
    avatar: null,
    is_guest: false,
});

const server = (
    id: number,
    overrides: Partial<CallMessage> = {},
): CallMessage => ({
    id,
    uuid: `uuid-${id}`,
    client_id: null,
    body: `message ${id}`,
    deleted: false,
    author: author('ada'),
    parent: null,
    reactions: [],
    files: [],
    created_at: new Date(2026, 0, 1, 10, 0, id).toISOString(),
    edited_at: null,
    ...overrides,
});

const optimistic = (clientId: string, me = 'me'): LocalMessage => ({
    ...server(-1, {
        uuid: `local-${clientId}`,
        client_id: clientId,
        author: author(me),
    }),
    created_at: new Date(2026, 0, 1, 11).toISOString(),
    state: 'pending',
});

describe('upsertMessage', () => {
    it('adds new messages in id order whatever order they arrive in', () => {
        const list = mergeMessages([], [server(3), server(1), server(2)]);

        expect(list.map((m) => m.id)).toEqual([1, 2, 3]);
        expect(list.every((m) => m.state === 'sent')).toBe(true);
    });

    it('replaces the optimistic entry when the server confirms it', () => {
        const list = upsertMessage(
            [optimistic('c1')],
            server(7, { client_id: 'c1', author: author('me') }),
        );

        expect(list).toHaveLength(1);
        expect(list[0]).toMatchObject({ id: 7, uuid: 'uuid-7', state: 'sent' });
    });

    it('stores a message once when the response and the broadcast both deliver it', () => {
        const confirmed = server(7, { client_id: 'c1', author: author('me') });
        const list = upsertMessage(
            upsertMessage([optimistic('c1')], confirmed),
            confirmed,
        );

        expect(list).toHaveLength(1);
    });

    it('does not confuse two people who happen to use the same client id', () => {
        const list = upsertMessage(
            [optimistic('c1', 'me')],
            server(8, { client_id: 'c1', author: author('someone-else') }),
        );

        expect(list).toHaveLength(2);
    });

    it('applies edits and deletions to the existing entry', () => {
        const list = upsertMessage(
            mergeMessages([], [server(1), server(2)]),
            server(1, { body: null, deleted: true }),
        );

        expect(list).toHaveLength(2);
        expect(list[0]).toMatchObject({ id: 1, deleted: true, body: null });
    });

    it('keeps unsent messages after everything the server confirmed', () => {
        const list = upsertMessage([optimistic('c1')], server(50));

        expect(list.map((m) => m.state)).toEqual(['sent', 'pending']);
    });
});

describe('send failures', () => {
    it('marks, retries and discards by client id without touching sent messages', () => {
        const base = mergeMessages(
            [optimistic('c1')],
            [server(1, { client_id: 'c1' })],
        );
        const failed = markFailed(base, 'c1', 'Not sent.');

        expect(failed.find((m) => m.state === 'failed')).toMatchObject({
            error: 'Not sent.',
        });
        expect(failed.find((m) => m.id === 1)?.state).toBe('sent');

        expect(
            markPending(failed, 'c1').some((m) => m.state === 'failed'),
        ).toBe(false);
        expect(removeLocal(failed, 'c1').map((m) => m.id)).toEqual([1]);
    });
});

describe('catch-up markers', () => {
    it('reports the first and last confirmed ids, ignoring unsent messages', () => {
        const list = mergeMessages([optimistic('c1')], [server(4), server(9)]);

        expect(lastConfirmedId(list)).toBe(9);
        expect(firstConfirmedId(list)).toBe(4);
        expect(lastConfirmedId([optimistic('c2')])).toBe(0);
        expect(firstConfirmedId([])).toBeNull();
    });
});

describe('countUnread', () => {
    it('counts other people’s messages after the read marker, not mine and not deleted ones', () => {
        const list = mergeMessages(
            [],
            [
                server(1),
                server(2),
                server(3, { author: author('me') }),
                server(4, { deleted: true, body: null }),
                server(5),
            ],
        );

        expect(countUnread(list, 1, 'me')).toBe(2);
        expect(countUnread(list, 5, 'me')).toBe(0);
    });
});

describe('continuesPrevious', () => {
    const at = (
        id: number,
        minutes: number,
        overrides: Partial<CallMessage> = {},
    ): LocalMessage => ({
        ...server(id, overrides),
        created_at: new Date(2026, 0, 1, 10, minutes).toISOString(),
        state: 'sent',
    });

    it('groups quick follow-ups by the same author', () => {
        expect(continuesPrevious(at(2, 1), at(1, 0))).toBe(true);
    });

    it('starts a new group for another author, a long pause, a reply or after a deletion', () => {
        expect(
            continuesPrevious(at(2, 1, { author: author('bob') }), at(1, 0)),
        ).toBe(false);
        expect(continuesPrevious(at(2, 9), at(1, 0))).toBe(false);
        expect(
            continuesPrevious(
                at(2, 1, {
                    parent: { uuid: 'x', author: 'ada', excerpt: 'hi' },
                }),
                at(1, 0),
            ),
        ).toBe(false);
        expect(continuesPrevious(at(2, 1), at(1, 0, { deleted: true }))).toBe(
            false,
        );
        expect(continuesPrevious(at(1, 0), undefined)).toBe(false);
    });
});
